#!/usr/bin/env python3
"""Confere integridade e imutabilidade dos pacotes, sem rede ou credenciais."""
from contextlib import redirect_stdout
from pathlib import Path
import hashlib
import importlib.util
import io
import json
import tempfile
import unittest
import warnings
import zipfile

spec = importlib.util.spec_from_file_location('package_builder', Path(__file__).with_name('build.py'))
builder = importlib.util.module_from_spec(spec)
spec.loader.exec_module(builder)


class Packages(unittest.TestCase):
    def setUp(self):
        self.workspace = tempfile.TemporaryDirectory()
        self.addCleanup(self.workspace.cleanup)
        self.root = Path(self.workspace.name)
        builder.ROOT = self.root
        self.plugin = self.root / 'k8-produtos'
        self.plugin.mkdir()
        self.set_version('1.2.3')
        (self.plugin / 'app.js').write_text('const value = 1;\n', 'utf-8')

    def set_version(self, version):
        (self.plugin / 'k8-produtos.php').write_text(f"<?php\n/**\n * Version: {version}\n */\ndefine('K8PROD_VERSION', '{version}');\n", 'utf-8')

    def build(self):
        with redirect_stdout(io.StringIO()):
            builder.build('k8-produtos', 'plugin', 'k8-produtos.php', 'Plugin teste', '<p>Teste.</p>')

    def archive(self, version='1.2.3'):
        return self.root / 'dist' / f'k8-produtos-{version}.zip'

    def digest(self, version='1.2.3'):
        return hashlib.sha256(self.archive(version).read_bytes()).hexdigest()

    def metadata(self):
        return json.loads((self.root / 'updates/plugin.json').read_text('utf-8'))

    def test_new_package_and_manifest_agree(self):
        self.build()
        metadata = self.metadata()
        self.assertEqual(metadata['name'], 'Plugin teste')
        self.assertEqual(metadata['version'], '1.2.3')
        self.assertEqual(metadata['sha256'], self.digest())
        self.assertTrue(metadata['package'].endswith('/dist/k8-produtos-1.2.3.zip'))
        with zipfile.ZipFile(self.archive()) as archive:
            self.assertIsNone(archive.testzip())
            self.assertIn('k8-produtos/k8-produtos.php', archive.namelist())

    def test_existing_validated_bytes_are_preserved(self):
        self.build()
        with zipfile.ZipFile(self.archive()) as archive:
            entries = [(name, archive.read(name)) for name in archive.namelist()]
        with zipfile.ZipFile(self.archive(), 'w', zipfile.ZIP_STORED) as archive:
            for name, data in entries:
                archive.writestr(zipfile.ZipInfo(name, date_time=(2026, 11, 10, 0, 0, 0)), data)
        validated_digest = self.digest()
        self.build()
        self.assertEqual(self.digest(), validated_digest)
        self.assertEqual(self.metadata()['name'], 'Plugin teste')
        self.assertEqual(self.metadata()['sha256'], validated_digest)

    def test_same_version_cannot_replace_published_content(self):
        self.build()
        previous_digest = self.digest()
        previous_metadata = self.metadata()
        (self.plugin / 'app.js').write_text('const value = 2;\n', 'utf-8')
        with self.assertRaises(SystemExit):
            self.build()
        self.assertEqual(self.digest(), previous_digest)
        self.assertEqual(self.metadata(), previous_metadata)

    def test_bumped_version_preserves_old_package(self):
        self.build()
        previous_digest = self.digest()
        self.set_version('1.2.4')
        (self.plugin / 'app.js').write_text('const value = 2;\n', 'utf-8')
        self.build()
        self.assertEqual(self.digest(), previous_digest)
        self.assertEqual(self.metadata()['name'], 'Plugin teste')
        self.assertEqual(self.metadata()['version'], '1.2.4')
        self.assertEqual(self.metadata()['sha256'], self.digest('1.2.4'))

    def test_duplicate_entries_and_broken_archives_are_rejected(self):
        self.build()
        with warnings.catch_warnings():
            warnings.simplefilter('ignore', UserWarning)
            with zipfile.ZipFile(self.archive(), 'a') as archive:
                archive.writestr('k8-produtos/app.js', b'duplicado')
        with self.assertRaises(SystemExit):
            self.build()
        self.archive().write_bytes(b'not-a-zip')
        with self.assertRaises(SystemExit):
            self.build()


if __name__ == '__main__':
    unittest.main()
