# -*- coding: utf-8 -*-
"""
build_site.py - Lấy dữ liệu bằng vnstock và tạo trang index.html kiểu Finbook.

Cài đặt (làm 1 lần, trong Terminal / CMD):
    pip install -U vnstock pandas

Chạy:
    - Sublime Text: Tools > Build System > Python, rồi Ctrl+B
    - hoặc Terminal:  python build_site.py ACB
Xong sẽ có index.html (tự mở trong trình duyệt) và columns.txt (để debug tên cột).
"""
import json, sys, datetime as dt, webbrowser, os, statistics
import pandas as pd
from vnstock import Vnstock

SYMBOL = (sys.argv[1] if len(sys.argv) > 1 else "ACB").upper()
SOURCES = ["VCI", "TCBS", "KBS"]          # thử lần lượt các nguồn
N_QUARTERS = 24                            # số quý hiển thị
HERE = os.path.dirname(os.path.abspath(__file__))


# ---------- tiện ích ----------
def fetch(fn):
    """Thử từng nguồn dữ liệu cho tới khi có kết quả."""
    for src in SOURCES:
        try:
            stock = Vnstock().stock(symbol=SYMBOL, source=src)
            df = fn(stock)
            if df is not None and len(df) > 0:
                print(f"  OK  nguồn {src}")
                return df
        except Exception as e:
            print(f"  --  nguồn {src} lỗi: {str(e)[:90]}")
    return pd.DataFrame()


def flat(df):
    """Làm phẳng cột MultiIndex, tránh trùng tên."""
    df = df.copy()
    if isinstance(df.columns, pd.MultiIndex):
        cols = [str(c[-1]) for c in df.columns]
    else:
        cols = [str(c) for c in df.columns]
    seen, out = {}, []
    for c in cols:
        seen[c] = seen.get(c, 0) + 1
        out.append(c if seen[c] == 1 else f"{c}_{seen[c]}")
    df.columns = out
    return df.reset_index(drop=True)


def find(df, keys, exact=False):
    """Tìm cột theo từ khóa (không phân biệt hoa thường)."""
    for k in keys:
        for c in df.columns:
            lc = c.lower().strip()
            if (lc == k) if exact else (k in lc):
                return c
    return None


def labels_for(df):
    y = find(df, ["yearreport", "year", "năm"])
    q = find(df, ["lengthreport", "quarter", "quý"])
    out = []
    for i in range(len(df)):
        try:
            yy = int(df.loc[i, y]) if y else i
            qq = int(df.loc[i, q]) if q else 0
            out.append(f"Q{qq}/{yy}" if 1 <= qq <= 4 else str(yy))
        except Exception:
            out.append(str(i))
    return out


def sort_chrono(df):
    y = find(df, ["yearreport", "year", "năm"])
    q = find(df, ["lengthreport", "quarter", "quý"])
    if y:
        by = [y] + ([q] if q else [])
        df = df.sort_values(by).reset_index(drop=True)
    return df


def series(df, col):
    out = []
    for v in df[col]:
        try:
            f = float(v)
            out.append(None if pd.isna(f) else round(f, 4))
        except Exception:
            out.append(None)
    return out


def nums(xs):
    return [x for x in xs if x is not None]


# ---------- lấy dữ liệu ----------
print(f"Đang lấy dữ liệu {SYMBOL} ...")

print("[1/4] Chỉ số tài chính (quý)")
def _ratio(s):
    try:
        return s.finance.ratio(period="quarter", lang="vi", dropna=True)
    except TypeError:
        return s.finance.ratio(period="quarter")
ratio = fetch(_ratio)

print("[2/4] Kết quả kinh doanh (quý)")
income = fetch(lambda s: s.finance.income_statement(period="quarter", lang="vi"))

print("[3/4] Bảng cân đối kế toán (quý)")
balance = fetch(lambda s: s.finance.balance_sheet(period="quarter", lang="vi"))

print("[4/4] Lịch sử giá")
start = (dt.date.today() - dt.timedelta(days=365 * 3)).strftime("%Y-%m-%d")
end = dt.date.today().strftime("%Y-%m-%d")
price = fetch(lambda s: s.quote.history(start=start, end=end, interval="1D"))

ratio, income, balance, price = [flat(d) if len(d) else d for d in (ratio, income, balance, price)]

# ghi tên cột để debug
with open(os.path.join(HERE, "columns.txt"), "w", encoding="utf-8") as f:
    for name, d in [("RATIO", ratio), ("INCOME", income), ("BALANCE", balance), ("PRICE", price)]:
        f.write(f"== {name} ==\n" + "\n".join(d.columns if len(d) else ["(trống)"]) + "\n\n")

charts, notes = [], []
header = {"symbol": SYMBOL, "price": None, "change": None, "stats": []}


def add_chart(title, labels, datasets, note=""):
    datasets = [d for d in datasets if nums(d["data"])]
    if datasets:
        charts.append({"title": title, "labels": labels, "datasets": datasets, "note": note})
    else:
        notes.append(f"Không tìm thấy dữ liệu cho: {title}")


# ---------- giá ----------
if len(price):
    pc, tc = find(price, ["close"], True), find(price, ["time", "date"])
    if pc and tc:
        price = price.sort_values(tc).reset_index(drop=True)
        closes = series(price, pc)
        header["price"] = closes[-1]
        if len(closes) > 1 and closes[-2]:
            header["change"] = round((closes[-1] / closes[-2] - 1) * 100, 2)
        step = max(1, len(price) // 400)
        lab = [str(x)[:10] for x in price[tc]][::step]
        add_chart("Giá đóng cửa (nghìn đồng)", lab,
                  [{"label": "Giá", "data": closes[::step], "type": "line"}])

# ---------- chỉ số tài chính ----------
if len(ratio):
    ratio = sort_chrono(ratio).tail(N_QUARTERS).reset_index(drop=True)
    lab = labels_for(ratio)
    cols = {
        "pe": find(ratio, ["p/e", "pe"]), "pb": find(ratio, ["p/b", "pb"]),
        "roe": find(ratio, ["roe"]), "roa": find(ratio, ["roa"]),
        "nim": find(ratio, ["nim"]), "casa": find(ratio, ["casa"]),
        "npl": find(ratio, ["npl", "nợ xấu"]), "cir": find(ratio, ["cir"]),
        "eps": find(ratio, ["eps"]), "bvps": find(ratio, ["bvps", "book value"]),
    }

    def val_chart(key, title):
        c = cols[key]
        if not c:
            notes.append(f"Không có cột {key.upper()}")
            return
        s = series(ratio, c)
        v = nums(s)
        if len(v) < 3:
            return
        med, avg, sd = statistics.median(v), statistics.mean(v), statistics.pstdev(v)
        cur = v[-1]
        pct = (cur / med - 1) * 100 if med else 0
        header["stats"].append([key.upper(), f"{cur:.2f}"])
        note = f"Hiện tại {cur:.2f} — {'thấp' if pct < 0 else 'cao'} hơn trung vị {abs(pct):.0f}%"
        L = len(s)
        add_chart(title, lab, [
            {"label": key.upper(), "data": s, "type": "line"},
            {"label": "Trung vị", "data": [round(med, 2)] * L, "type": "line", "dash": True},
            {"label": "+1 StDv", "data": [round(avg + sd, 2)] * L, "type": "line", "dash": True},
            {"label": "-1 StDv", "data": [round(avg - sd, 2)] * L, "type": "line", "dash": True},
        ], note)

    val_chart("pe", "Định giá P/E (theo quý)")
    val_chart("pb", "Định giá P/B (theo quý)")

    add_chart("ROE & ROA (%)", lab, [
        {"label": "ROE", "data": series(ratio, cols["roe"]) if cols["roe"] else [], "type": "line"},
        {"label": "ROA", "data": series(ratio, cols["roa"]) if cols["roa"] else [], "type": "line"}])
    add_chart("NIM & CASA (%)", lab, [
        {"label": "NIM", "data": series(ratio, cols["nim"]) if cols["nim"] else [], "type": "line"},
        {"label": "CASA", "data": series(ratio, cols["casa"]) if cols["casa"] else [], "type": "line"}])
    add_chart("Nợ xấu & CIR (%)", lab, [
        {"label": "Nợ xấu", "data": series(ratio, cols["npl"]) if cols["npl"] else [], "type": "line"},
        {"label": "CIR", "data": series(ratio, cols["cir"]) if cols["cir"] else [], "type": "line"}])
    for k in ("roe", "eps", "bvps"):
        if cols[k] and nums(series(ratio, cols[k])):
            header["stats"].append([k.upper(), f"{nums(series(ratio, cols[k]))[-1]:,.2f}"])


# ---------- kết quả kinh doanh / cân đối ----------
def money_chart(df, title, spec):
    if not len(df):
        notes.append(f"Thiếu dữ liệu: {title}")
        return
    df = sort_chrono(df).tail(N_QUARTERS).reset_index(drop=True)
    ds = []
    for label, keys, kind in spec:
        c = find(df, keys)
        if c:
            ds.append({"label": label, "type": kind,
                       "data": [None if v is None else round(v / 1e9, 1) for v in series(df, c)]})
    add_chart(title, labels_for(df), ds)

money_chart(income, "Thu nhập lãi thuần & Lợi nhuận sau thuế (tỷ đồng)", [
    ("Thu nhập lãi thuần", ["thu nhập lãi thuần", "net interest income"], "bar"),
    ("Lợi nhuận sau thuế", ["lợi nhuận sau thuế", "net profit", "profit after tax"], "line")])
money_chart(balance, "Cho vay & Tiền gửi khách hàng (tỷ đồng)", [
    ("Cho vay khách hàng", ["cho vay khách hàng", "loans"], "bar"),
    ("Tiền gửi khách hàng", ["tiền gửi của khách hàng", "customer deposits", "deposits"], "line")])

# ---------- xuất HTML ----------
data = {"header": header, "charts": charts, "notes": notes,
        "updated": dt.datetime.now().strftime("%d/%m/%Y %H:%M")}

TEMPLATE = r"""<!DOCTYPE html>
<html lang="vi"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Phân tích cổ phiếu</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<style>
*{box-sizing:border-box}body{margin:0;font-family:Segoe UI,Arial,sans-serif;background:#f1f3f6;color:#222}
header{background:#0f4a8a;color:#fff;padding:14px 24px;font-size:20px;font-weight:600}
main{max-width:1280px;margin:0 auto;padding:20px}
.top{display:flex;gap:16px;flex-wrap:wrap;margin-bottom:16px}
.card{background:#fff;border-radius:10px;padding:16px;box-shadow:0 1px 3px rgba(0,0,0,.08)}
.tick{min-width:220px}.tick h2{margin:0 0 6px;font-size:22px}
.px{font-size:38px;font-weight:700}.up{color:#16a34a}.down{color:#dc2626}
.stats{flex:1;display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:10px}
.stats div{background:#f6f8fb;border-radius:8px;padding:10px}.stats b{display:block;font-size:18px}
.stats span{font-size:12px;color:#667}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(400px,1fr));gap:16px}
.card h3{margin:0 0 4px;font-size:15px;color:#0f4a8a}.note{font-size:13px;color:#b45309;margin-bottom:6px;min-height:16px}
canvas{max-height:300px}.warn{margin-top:16px;font-size:13px;color:#667}
footer{text-align:center;color:#889;font-size:12px;padding:20px}
</style></head><body>
<header id="hd"></header>
<main>
<div class="top">
 <div class="card tick"><h2 id="sym"></h2><div class="px" id="px">-</div><div id="chg"></div></div>
 <div class="card stats" id="stats"></div>
</div>
<div class="grid" id="grid"></div>
<div class="warn" id="warn"></div>
</main>
<footer id="ft"></footer>
<script>
const D = __DATA__;
const COLORS=["#f97316","#2563eb","#6b7280","#16a34a","#dc2626","#8b5cf6"];
document.getElementById("hd").textContent="Phân tích cổ phiếu "+D.header.symbol;
document.getElementById("sym").textContent=D.header.symbol;
if(D.header.price!=null){document.getElementById("px").textContent=D.header.price.toFixed(2);}
if(D.header.change!=null){const e=document.getElementById("chg");e.textContent=(D.header.change>0?"+":"")+D.header.change+"%";e.className=D.header.change>=0?"up":"down";}
document.getElementById("stats").innerHTML=D.header.stats.map(s=>`<div><span>${s[0]}</span><b>${s[1]}</b></div>`).join("");
const grid=document.getElementById("grid");
D.charts.forEach((c,i)=>{
 const card=document.createElement("div");card.className="card";
 card.innerHTML=`<h3>${c.title}</h3><div class="note">${c.note||""}</div><canvas id="c${i}"></canvas>`;
 grid.appendChild(card);
 new Chart(document.getElementById("c"+i),{
  data:{labels:c.labels,datasets:c.datasets.map((d,j)=>({
    type:d.type,label:d.label,data:d.data,borderColor:COLORS[j%6],backgroundColor:d.type==="bar"?"rgba(150,160,175,.55)":COLORS[j%6],
    borderWidth:d.dash?1.2:2,borderDash:d.dash?[5,4]:[],pointRadius:0,tension:.25,spanGaps:true}))},
  options:{responsive:true,interaction:{mode:"index",intersect:false},
   scales:{x:{ticks:{maxTicksLimit:10,maxRotation:60}}},plugins:{legend:{position:"bottom"}}}});
});
document.getElementById("warn").textContent=D.notes.join(" • ");
document.getElementById("ft").textContent="Dữ liệu qua vnstock • Cập nhật "+D.updated+" • Chỉ mang tính tham khảo, không phải khuyến nghị đầu tư.";
</script></body></html>"""

out = os.path.join(HERE, "index.html")
with open(out, "w", encoding="utf-8") as f:
    f.write(TEMPLATE.replace("__DATA__", json.dumps(data, ensure_ascii=False)))
print(f"\nXong: {out}  ({len(charts)} biểu đồ)")
for n in notes:
    print("  !", n)
webbrowser.open("file://" + out)
