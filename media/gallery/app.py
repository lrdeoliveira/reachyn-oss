import os
import boto3
from botocore.client import Config
from flask import Flask, render_template_string, redirect, request

ENDPOINT = os.environ.get("S3_ENDPOINT", "http://scality-s3:8000")
PUBLIC_BASE = os.environ.get("PUBLIC_BASE", "https://s3.example.com/public")
BUCKET = os.environ.get("S3_BUCKET", "public")
PREFIX = os.environ.get("S3_PREFIX", "reachyn/")

s3 = boto3.client(
    "s3", endpoint_url=ENDPOINT,
    aws_access_key_id=os.environ["S3_ACCESS_KEY"],
    aws_secret_access_key=os.environ["S3_SECRET_KEY"],
    config=Config(s3={"addressing_style": "path"}), region_name="us-east-1",
)

IMG = (".jpg", ".jpeg", ".png", ".webp", ".gif")
VID = (".mp4", ".mov", ".webm", ".m4v")

TPL = """<!doctype html><html lang=pt-br><head><meta charset=utf-8>
<meta name=viewport content="width=device-width,initial-scale=1">
<title>RedFoxCode — Mídia gerada</title>
<style>
 body{background:#08060f;color:#eee;font-family:system-ui,sans-serif;margin:0;padding:24px}
 h1{font-weight:600;font-size:20px} h1 span{color:#7c7596;font-weight:400}
 .bar{margin:8px 0 18px;color:#9a93b3;font-size:13px}
 .bar a{color:#a98bff;text-decoration:none;margin-right:14px}
 .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:14px}
 .card{background:#15101f;border:1px solid #281f3d;border-radius:12px;overflow:hidden}
 .card img,.card video{width:100%;height:170px;object-fit:cover;display:block;background:#000}
 .ph{height:170px;display:flex;align-items:center;justify-content:center;color:#5a5470;font-size:12px}
 .meta{padding:9px 10px;font-size:12px;color:#9a93b3;word-break:break-all}
 .meta a{color:#cdbcff;text-decoration:none}
 .row{display:flex;justify-content:space-between;align-items:center;margin-top:6px}
 button{background:#3a1f2e;color:#ffb3c1;border:1px solid #5a2a3c;border-radius:6px;padding:3px 9px;cursor:pointer;font-size:11px}
 button:hover{background:#5a2a3c}
</style></head><body>
<h1>RedFoxCode — Mídia gerada <span>({{items|length}} arquivos · {{total}})</span></h1>
<div class=bar>Acervo do bucket <b>{{bucket}}/{{prefix}}</b> · storage Scality (S3) · só seu IP</div>
<div class=grid>
{% for it in items %}
 <div class=card>
  {% if it.kind=='img' %}<a href="{{it.url}}" target=_blank rel=noopener><img loading=lazy src="{{it.url}}"></a>
  {% elif it.kind=='vid' %}<video controls preload=metadata src="{{it.url}}"></video>
  {% else %}<div class=ph>{{it.ext or 'arquivo'}}</div>{% endif %}
  <div class=meta>
   <a href="{{it.url}}" target=_blank rel=noopener>{{it.name}}</a>
   <div class=row><span>{{it.size}} · {{it.date}}</span>
    <form method=post action=delete onsubmit="return confirm('Apagar {{it.name}}?')">
     <input type=hidden name=key value="{{it.key}}"><button>apagar</button></form>
   </div>
  </div>
 </div>
{% endfor %}
</div>
{% if not items %}<p style=color:#7c7596>Nenhum arquivo no acervo ainda.</p>{% endif %}
</body></html>"""

app = Flask(__name__)


def human(n):
    for u in ("B", "KB", "MB", "GB"):
        if n < 1024:
            return f"{n:.0f} {u}"
        n /= 1024
    return f"{n:.0f} TB"


@app.route("/")
def index():
    items, total = [], 0
    for page in s3.get_paginator("list_objects_v2").paginate(Bucket=BUCKET, Prefix=PREFIX):
        for o in page.get("Contents", []):
            k = o["Key"]
            if k.endswith("/"):
                continue
            ext = os.path.splitext(k)[1].lower()
            kind = "img" if ext in IMG else "vid" if ext in VID else "other"
            total += o["Size"]
            items.append({
                "key": k, "name": k.split("/")[-1], "url": f"{PUBLIC_BASE}/{k}",
                "kind": kind, "ext": ext.lstrip("."), "size": human(o["Size"]),
                "date": o["LastModified"].strftime("%Y-%m-%d %H:%M"),
            })
    items.sort(key=lambda x: x["date"], reverse=True)
    return render_template_string(TPL, items=items, total=human(total), bucket=BUCKET, prefix=PREFIX)


@app.route("/delete", methods=["POST"])
def delete():
    k = request.form.get("key", "")
    if k.startswith(PREFIX):
        s3.delete_object(Bucket=BUCKET, Key=k)
    return redirect("/")


@app.route("/health")
def health():
    return {"ok": True}
