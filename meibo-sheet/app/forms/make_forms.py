#!/usr/bin/env python3
# Builds the daily forms (水分チェック表 / 排泄チェック表) as one Excel workbook: one sheet = one day (one A4 page).
# usage: make_forms.py INPUT.json OUTPUT.xlsx
# INPUT: {"kind": "water" | "excr", "days": [{"date": "2026-10-18", "names": ["豊田　栄子", ...]}, ...]}
# The names are the people of that day who come in during the day (通い) or stay overnight (泊まり); home visits are not listed.
# Four empty rows are added at the end for handwritten names (5 when the list is short).
import copy, datetime, json, os, shutil, subprocess, sys, tempfile
import openpyxl
from openpyxl.utils import get_column_letter

HERE = os.path.dirname(os.path.abspath(__file__))
TEMPLATE = {'water': os.path.join(HERE, '水分表.xlsx'), 'excr': os.path.join(HERE, '排泄表.xlsx')}
LABEL = {'water': '水分チェック表', 'excr': '排泄チェック表'}
WD = '月火水木金土日'

def fill_sheet(ws, kind, pattern, date_str, names, extra_blank):
    y, m, d = map(int, date_str.split('-'))
    wd = WD[datetime.date(y, m, d).weekday()]
    max_col = ws.max_column
    # 1) remove everything below the header (rows 3 and further): merges and cells
    for rng in list(ws.merged_cells.ranges):
        if rng.min_row >= 3:
            ws.unmerge_cells(str(rng))
    for key in list(ws._cells.keys()):
        if key[0] >= 3:
            del ws._cells[key]
    for r in list(ws.row_dimensions.keys()):
        if r >= 3:
            del ws.row_dimensions[r]
    # 2) one slot = two rows (H and K for 排泄; one merged name for 水分 uses the same two rows)
    slots = [(n, True) for n in names] + [('', True)] * extra_blank
    last_row = 2 + 2 * len(slots)
    for i, (name, _) in enumerate(slots):
        r1 = 3 + 2 * i
        r2 = r1 + 1
        for c in range(1, max_col + 1):
            ws.cell(r1, c)._style = copy.copy(pattern['row3'][c])
            ws.cell(r2, c)._style = copy.copy(pattern['row4'][c])
        ws.row_dimensions[r1].height = pattern['h3']
        ws.row_dimensions[r2].height = pattern['h4']
        for (c1, rr1, c2, rr2) in pattern['merges']:
            ws.merge_cells(start_row=r1 + (rr1 - 3), start_column=c1, end_row=r1 + (rr2 - 3), end_column=c2)
        ws.cell(r1, 1).value = i + 1
        ws.cell(r1, 2).value = name if name else None
        if kind == 'excr':
            ws.cell(r1, 3).value = 'H'
            ws.cell(r2, 3).value = 'K'
    # 3) title and print area (one A4 page per day)
    ws.cell(1, 1).value = '%s\u3000\u3000\u3000\u3000\u3000\u3000\u3000\u3000\u3000\u3000%d年\u3000%d月\u3000%d日\u3000%s曜日' % (LABEL[kind], y, m, d, wd)
    ws.print_area = 'A1:%s%d' % (get_column_letter(max_col), last_row)
    ws.sheet_properties.pageSetUpPr.fitToPage = True
    ws.page_setup.fitToWidth = 1
    ws.page_setup.fitToHeight = 1
    ws.page_setup.orientation = 'landscape'
    ws.title = date_str

def capture(tpl):
    max_col = tpl.max_column
    pattern = {
        'row3': {c: copy.copy(tpl.cell(3, c)._style) for c in range(1, max_col + 1)},
        'row4': {c: copy.copy(tpl.cell(4, c)._style) for c in range(1, max_col + 1)},
        'h3': tpl.row_dimensions[3].height,
        'h4': tpl.row_dimensions[4].height,
        'merges': [],
    }
    for rng in tpl.merged_cells.ranges:
        if rng.min_row >= 3 and rng.max_row <= 4:
            pattern['merges'].append((rng.min_col, rng.min_row, rng.max_col, rng.max_row))
    return pattern

def to_pdf(xlsx_path, pdf_path):
    # LibreOffice converts the workbook: one sheet = one page
    profile = tempfile.mkdtemp(prefix='lo')
    outdir = tempfile.mkdtemp(prefix='pdf')
    subprocess.run(['soffice', '-env:UserInstallation=file://' + profile, '--headless', '--convert-to', 'pdf', '--outdir', outdir, xlsx_path],
                   check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=240)
    produced = os.path.join(outdir, os.path.splitext(os.path.basename(xlsx_path))[0] + '.pdf')
    shutil.move(produced, pdf_path)
    shutil.rmtree(profile, ignore_errors=True)
    shutil.rmtree(outdir, ignore_errors=True)

def build(kind, days, out_path):
    wb = openpyxl.load_workbook(TEMPLATE[kind])
    tpl = wb.worksheets[0]
    pattern = capture(tpl)
    originals = list(wb.worksheets)
    for day in days:
        ws = wb.copy_worksheet(tpl)
        names = [n.strip() for n in day.get('names', []) if str(n).strip()]
        fill_sheet(ws, kind, pattern, day['date'], names, 5)
    for s in originals:
        wb.remove(s)
    wb.save(out_path)

if __name__ == '__main__':
    # usage: make_forms.py INPUT.json OUTPUT.pdf
    with open(sys.argv[1], encoding='utf-8') as f:
        data = json.load(f)
    tmpdir = tempfile.mkdtemp(prefix='xl')
    xlsx = os.path.join(tmpdir, 'forms.xlsx')
    build(data['kind'], data['days'], xlsx)
    to_pdf(xlsx, sys.argv[2])
    shutil.rmtree(tmpdir, ignore_errors=True)
    print('ok')
