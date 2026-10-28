#!/usr/bin/env python3
"""Gera pacotes instaláveis e os dois manifestos de atualização. Sem credenciais."""
from pathlib import Path
import hashlib
import json
import re
import zipfile

ROOT = Path(__file__).resolve().parent.parent
BASE = 'https://raw.githubusercontent.com/paulommaeda/materiais/main/'


def build(slug, kind, header, name, description):
    text = (ROOT / slug / header).read_text(encoding='utf-8')
    match = re.search(r'^\s*(?:\*\s*)?Version:\s*([\d.]+)\s*$', text, re.M)
    if not match:
        raise SystemExit(f'Versão ausente: {slug}/{header}')
    version = match.group(1)
    if slug == 'k8-produtos' and f"define('K8PROD_VERSION', '{version}');" not in text:
        raise SystemExit('O cabeçalho e K8PROD_VERSION devem ter a mesma versão.')
    dest = ROOT / 'dist' / f'{slug}-{version}.zip'
    dest.parent.mkdir(exist_ok=True)
    with zipfile.ZipFile(dest, 'w', zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for path in sorted((ROOT / slug).rglob('*')):
            if not path.is_file() or any(part.startswith('.') or part == '__pycache__' for part in path.relative_to(ROOT / slug).parts):
                continue
            info = zipfile.ZipInfo(path.relative_to(ROOT).as_posix(), date_time=(2026, 1, 1, 0, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o100644 << 16
            archive.writestr(info, path.read_bytes())
    digest = hashlib.sha256(dest.read_bytes()).hexdigest()
    metadata = {
        'name': name, 'slug': slug, 'version': version, 'requires': '6.2', 'requires_php': '7.4',
        'tested': '7.1.3', 'package': BASE + f'dist/{dest.name}', 'sha256': digest,
        'description': description,
        'changelog': f'<p>Versão {version}. Consulte CHANGELOG.md no repositório para as alterações.</p>',
    }
    (ROOT / 'updates').mkdir(exist_ok=True)
    (ROOT / 'updates' / f'{kind}.json').write_text(json.dumps(metadata, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
    with zipfile.ZipFile(dest) as archive:
        assert archive.testzip() is None
        assert f'{slug}/{header}' in archive.namelist()
    print(f'{dest.name}: {dest.stat().st_size} bytes — SHA256 {digest}')


if __name__ == '__main__':
    assert (ROOT / 'k8-produtos/includes/update-channel.php').read_bytes() == (ROOT / 'bmt-educacional/update-channel.php').read_bytes(), 'Sincronize o canal de atualização nos dois componentes.'
    build('k8-produtos', 'plugin', 'k8-produtos.php', 'K8 Produtos, Alunos, Cobranças e Entregas', '<p>Catálogo, produtos por shortcode, base de alunos com CSV, cobrança K8, cupons, estoque e entrega com assinatura.</p>')
    build('bmt-educacional', 'theme', 'style.css', 'BMT Educacional', '<p>Site BMT Comercial com catálogo integrado de materiais didáticos, paradidáticos e eventos escolares.</p>')
