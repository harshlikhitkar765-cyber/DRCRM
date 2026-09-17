#!/usr/bin/env python3
"""
Accuracy harness for the ambient scribe.

The earlier tests drove one scripted consultation and printed the result for
a human to eyeball. That proves the pipeline runs; it does not measure how
often it is right.

This runs every case in cases.json through the real page, compares each
extracted field against a hand-written expected answer, and reports a score
per field type. A regression now shows up as a number going down, not as a
line of output somebody has to notice.

    python3 tests/accuracy.py            # score everything
    python3 tests/accuracy.py -v         # also list each mismatch
"""
import json, os, sys, datetime
from playwright.sync_api import sync_playwright

BASE = os.environ.get("CLINIC_URL", "http://127.0.0.1:3000/")
HERE = os.path.dirname(os.path.abspath(__file__))
VERBOSE = "-v" in sys.argv

# A speech engine that replays a scripted consultation, so the score
# measures OUR extraction and not Google's transcription.
FAKE = """
window.__mk=()=>{ class FakeRec{
 constructor(){this.continuous=false;this.interimResults=false;this.lang='en-IN';window.__R=this;}
 start(){ if(this._on) throw new DOMException('x','InvalidStateError'); this._on=true;
  setTimeout(()=>{this.onstart&&this.onstart();this.onaudiostart&&this.onaudiostart();},0);}
 stop(){this._on=false;setTimeout(()=>{this.onend&&this.onend();},0);}
 say(t){ this.onspeechstart&&this.onspeechstart();
  this.onresult&&this.onresult({resultIndex:0,results:[{isFinal:true,0:{transcript:t},length:1}]});}}
 window.SpeechRecognition=FakeRec; window.webkitSpeechRecognition=FakeRec; };
window.__mk();
"""


def norm(s):
    return " ".join(str(s or "").lower().split())


def days_between(iso):
    """Follow-up is stored as a date; the case files state an interval."""
    if not iso:
        return None
    try:
        d = datetime.date.fromisoformat(iso)
        return (d - datetime.date.today()).days
    except ValueError:
        return None


def read_form(pg):
    return pg.evaluate("""() => {
        const v = s => { const e = document.querySelector(s); return e ? e.value.trim() : ''; };
        const all = s => [...document.querySelectorAll(s)].map(e => e.value.trim());
        const meds = [...document.querySelectorAll('[name="med_name[]"]')]
            .map((e, i) => ({
                name: e.value.trim(),
                when: all('[name="med_when[]"]')[i] || '',
                freq: all('[name="med_freq[]"]')[i] || '',
                dur:  all('[name="med_dur[]"]')[i]  || ''
            }))
            .filter(m => m.name);
        return {
            diagnosis: v('[name="diagnosis"]'),
            v_temp: v('[name="v_temp"]'), v_bp: v('[name="v_bp"]'),
            v_pulse: v('[name="v_pulse"]'), v_sugar: v('[name="v_sugar"]'),
            v_spo2: v('[name="v_spo2"]'), v_weight: v('[name="v_weight"]'),
            labs: v('#labsInput'), advice: v('[name="advice"]'),
            follow: v('[name="follow_up"]'), meds
        };
    }""")


def score_case(pg, case):
    """Return (checks, fails) where each check is (field, ok, got, want)."""
    pg.goto(BASE + "consult.php?appt=1")
    pg.wait_for_timeout(500)
    pg.evaluate("() => { const s=document.getElementById('scribePanel'); if(s) s.style.display=''; }")

    # Select the language the case is spoken in.
    pg.evaluate("l => { const s=document.getElementById('scribeLang'); if(s) s.value=l; }", case["lang"])
    pg.fill("#scribeText", case["say"])
    pg.dispatch_event("#scribeText", "input")
    pg.click("#scribeMake")
    pg.wait_for_timeout(700)
    pg.click("#scribeApply")
    pg.wait_for_timeout(450)

    got = read_form(pg)
    exp = case["expect"]
    checks = []

    def add(field, ok, g, w):
        checks.append((field, ok, g, w))

    if "diagnosis" in exp:
        add("diagnosis", norm(exp["diagnosis"]) in norm(got["diagnosis"]),
            got["diagnosis"], exp["diagnosis"])

    if exp.get("noDiagnosisWord"):
        # Nothing was diagnosed aloud, so the box should hold complaints,
        # not an invented diagnosis.
        add("diagnosis(none)", got["diagnosis"] != "" , got["diagnosis"], "complaints, not blank")

    for vit in ("v_temp", "v_bp", "v_pulse", "v_sugar", "v_spo2", "v_weight"):
        if vit in exp:
            add(vit, norm(got[vit]) == norm(exp[vit]), got[vit], exp[vit])

    if "meds" in exp:
        names = [m["name"] for m in got["meds"]]
        add("medicines", [norm(n) for n in names] == [norm(n) for n in exp["meds"]],
            names, exp["meds"])
        for key, field in (("med_when", "when"), ("med_freq", "freq"), ("med_dur", "dur")):
            if key in exp:
                vals = [m[field] for m in got["meds"]][:len(exp[key])]
                add(key, [norm(x) for x in vals] == [norm(x) for x in exp[key]],
                    vals, exp[key])

    if "allergies" in exp:
        got_al = pg.evaluate("() => { const e=document.getElementById('scribeAllergy');"
                             " return e ? e.value : ''; }")
        have = [x.strip() for x in got_al.split(",") if x.strip()]
        add("allergies", [norm(x) for x in have] == [norm(x) for x in exp["allergies"]],
            have, exp["allergies"])

    if "noSymptom" in exp:
        # The reaction described in an allergy history must not be logged
        # as a complaint the patient has today.
        dx = norm(got["diagnosis"])
        bad = [w for w in exp["noSymptom"] if norm(w) in dx]
        add("no-false-symptom", not bad, got["diagnosis"], f"must not contain {exp['noSymptom']}")

    if "labs" in exp:
        have = [norm(x) for x in got["labs"].split(",") if x.strip()]
        want = [norm(x) for x in exp["labs"]]
        add("labs", all(w in have for w in want), got["labs"], exp["labs"])

    if "followDays" in exp:
        d = days_between(got["follow"])
        add("follow-up", d == exp["followDays"], f"{got['follow']} ({d}d)", f"{exp['followDays']}d")

    return checks


def main():
    cases = json.load(open(os.path.join(HERE, "cases.json")))
    totals, fails = {}, []

    with sync_playwright() as p:
        br = p.chromium.launch()
        pg = br.new_page()
        pg.add_init_script(FAKE)
        pg.goto(BASE + "login.php")
        pg.wait_for_timeout(300)
        pg.click('.auth-demo-b[value="doctor"]')
        pg.wait_for_load_state()

        print(f"{'case':22} {'checks':>7} {'passed':>7}")
        print("-" * 40)
        for c in cases:
            checks = score_case(pg, c)
            ok = sum(1 for _, o, _, _ in checks if o)
            print(f"{c['id']:22} {len(checks):>7} {ok:>7}")
            for field, o, g, w in checks:
                t = totals.setdefault(field.split("(")[0], [0, 0])
                t[0] += 1
                t[1] += 1 if o else 0
                if not o:
                    fails.append((c["id"], field, g, w))
        br.close()

    print("\nBy field")
    print("-" * 46)
    gt = gp = 0
    for field in sorted(totals):
        n, ok = totals[field]
        gt += n; gp += ok
        bar = "#" * round(ok / n * 20)
        print(f"  {field:14} {ok:>3}/{n:<3} {round(ok/n*100):>3}%  {bar}")

    pct = round(gp / gt * 100) if gt else 0
    print("-" * 46)
    print(f"  {'OVERALL':14} {gp:>3}/{gt:<3} {pct:>3}%")

    if fails and (VERBOSE or len(fails) <= 12):
        print("\nMismatches")
        print("-" * 46)
        for cid, field, g, w in fails:
            print(f"  {cid:22} {field}")
            print(f"      got  {g}")
            print(f"      want {w}")

    return 0 if pct == 100 else 1


if __name__ == "__main__":
    sys.exit(main())
