#!/usr/bin/env python3
# Daily forms as PDF. The layout is the uploaded example (template_water.pdf / template_excr.pdf):
# the old names and the date are covered and the names and the date of the day are written in the same places.
# usage: make_forms.py INPUT.json OUTPUT.pdf
# INPUT: {"kind": "water" | "excr", "days": [{"date": "2026-10-24", "names": ["豊田　栄子", ...]}, ...]}
# Home visits are not listed (only 通い and 泊まり). One day = one page.
import datetime, io, json, os, sys
from pypdf import PdfReader, PdfWriter
from reportlab.pdfgen import canvas
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont

HERE = os.path.dirname(os.path.abspath(__file__))
FONT = next(p for p in ['/usr/share/fonts/opentype/ipafont-gothic/ipag.ttf',
                        '/usr/share/fonts/truetype/fonts-japanese-gothic.ttf'] if os.path.exists(p))
pdfmetrics.registerFont(TTFont('JP', FONT))
WD = '月火水木金土日'
TEMPLATE = {'water': os.path.join(HERE, 'template_water.pdf'), 'excr': os.path.join(HERE, 'template_excr.pdf')}
# measured positions in the example (points, top-left origin); h = height of the word box, fs = h / 1.45
GEOM = {
    'water': dict(name_x=34.43, align='left', erase=(34.6, 88.6), name_first=85.2758, name_pitch=22.3198, name_h=9.85,
                  date_x=(431.795, 471.55, 498.457, 527.1), date_y=56.289, date_h=10.52,
                  date_cover=(425, 53, 560, 70)),
    'excr': dict(name_x=78.6, align='centre', erase=(35.5, 121.5), name_first=76.6386, name_pitch=26.467, name_h=14.77,
                 date_x=(434.765, 480.425, 511.479, 544.763), date_y=34.334, date_h=12.33,
                 date_cover=(430, 31, 580, 49)),
}
SLOTS = 15

def draw_day(kind, date_str, names):
    g = GEOM[kind]
    reader = PdfReader(TEMPLATE[kind])
    page = reader.pages[0]
    pw = float(page.mediabox.width)
    ph = float(page.mediabox.height)
    buf = io.BytesIO()
    c = canvas.Canvas(buf, pagesize=(pw, ph))
    fs_name = g['name_h'] / 1.45
    # 1) cover the old names (all slots) and the old date
    c.setFillColorRGB(1, 1, 1)
    for i in range(SLOTS):
        top = g['name_first'] + g['name_pitch'] * i - 1.5
        ex0, ex1 = g['erase']
        c.rect(ex0, ph - (top + g['name_h'] + 1.5), ex1 - ex0, g['name_h'] + 3, stroke=0, fill=1)
    x0, y0, x1, y1 = g['date_cover']
    c.rect(x0, ph - y1, x1 - x0, y1 - y0, stroke=0, fill=1)
    # 2) names of the day, centred, same size as in the example
    c.setFillColorRGB(0, 0, 0)
    c.setFont('JP', fs_name)
    for i, name in enumerate(names):
        top = g['name_first'] + g['name_pitch'] * i
        if g['align'] == 'left':
            c.drawString(g['name_x'], ph - (top + g['name_h'] * 0.78), name)
        else:
            c.drawCentredString(g['name_x'], ph - (top + g['name_h'] * 0.78), name)
    # 3) the date (automatic)
    y, m, d = map(int, date_str.split('-'))
    wd = WD[datetime.date(y, m, d).weekday()]
    fs_date = g['date_h'] / 1.45
    c.setFont('JP', fs_date)
    base = ph - (g['date_y'] + g['date_h'] * 0.78)
    xs = g['date_x']
    c.drawString(xs[0], base, '%d年' % y)
    c.drawString(xs[1], base, '%d月' % m)
    c.drawString(xs[2], base, '%d日' % d)
    c.drawString(xs[3], base, '%s曜日' % wd)
    c.save()
    buf.seek(0)
    page.merge_page(PdfReader(buf).pages[0])
    return page

def build(kind, days, out_path):
    writer = PdfWriter()
    for day in days:
        names = [n.strip() for n in day.get('names', []) if str(n).strip()]
        if len(names) > SLOTS:
            raise ValueError('more than %d people on the day: %s' % (SLOTS, day['date']))
        writer.add_page(draw_day(kind, day['date'], names))
    with open(out_path, 'wb') as f:
        writer.write(f)

if __name__ == '__main__':
    with open(sys.argv[1], encoding='utf-8') as f:
        data = json.load(f)
    build(data['kind'], data['days'], sys.argv[2])
    print('ok')
