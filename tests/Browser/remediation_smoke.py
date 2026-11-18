#!/usr/bin/env python3
"""Real Chrome smoke; requires an isolated browser on CDP port 19279."""
import base64
import json
import os
from pathlib import Path
import time
import sys
import requests
import websocket

BROWSER_PASSWORD = os.environ['SVU_BROWSER_PASSWORD']
ROOT = Path(__file__).resolve().parents[2]
FINAL = '--final-rehearsal' in sys.argv
DEPLOYMENT = FINAL or '--deployment-rehearsal' in sys.argv
BASE = 'http://127.0.0.1:' + ('18081' if FINAL else '18080' if DEPLOYMENT else '18079')
target = requests.put('http://127.0.0.1:19279/json/new?' + BASE + '/admin/login').json()
ws = websocket.create_connection(target['webSocketDebuggerUrl'], origin='http://localhost:19279', timeout=30)
serial = 0
errors = []


def call(method, **params):
    global serial
    serial += 1
    current = serial
    ws.send(json.dumps({'id': current, 'method': method, 'params': params}))
    while True:
        event = json.loads(ws.recv())
        if event.get('method') == 'Runtime.exceptionThrown':
            errors.append(event['params']['exceptionDetails'])
        if event.get('id') == current:
            if 'error' in event:
                raise RuntimeError(event['error'])
            return event.get('result', {})


def evaluate(expression):
    result = call('Runtime.evaluate', expression=expression, returnByValue=True, awaitPromise=True)
    if result.get('exceptionDetails'):
        raise RuntimeError(result['exceptionDetails'])
    return result.get('result', {}).get('value')


def wait(expression, timeout=30):
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        if evaluate(expression):
            return
        time.sleep(.25)
    raise RuntimeError('Browser condition timed out: ' + expression)


def navigate(path):
    call('Page.navigate', url=BASE + path)
    wait('document.readyState === "complete" && location.pathname === ' + json.dumps(path))
    wait('!!window.Alpine')


call('Page.enable')
call('Runtime.enable')
call('Network.enable')
call('Emulation.setDeviceMetricsOverride', width=1440, height=1000, deviceScaleFactor=1, mobile=False)
wait('!!document.querySelector("input[type=email]")')
wait('!!window.Alpine')
evaluate('''(() => {
 const fill = (selector, value) => {const element = document.querySelector(selector); element.value=value; element.dispatchEvent(new Event('input', {bubbles:true})); element.dispatchEvent(new Event('change', {bubbles:true}));};
 fill('input[type=email]', EMAIL_FIXTURE);
 fill('input[type=password]', PASSWORD_FIXTURE);
 document.querySelector('button[type=submit]').click();
})()'''.replace('EMAIL_FIXTURE', json.dumps('rehearsal@example.invalid' if DEPLOYMENT else 'remediation@example.invalid')).replace('PASSWORD_FIXTURE', json.dumps(BROWSER_PASSWORD)))
wait('location.pathname === "/admin"', 40)
results = []
for locale, direction, label in [('en', 'ltr', 'Phase I baseline preparation queue'), ('ar', 'rtl', 'طابور تحضير خطوط أساس المرحلة الأولى')]:
    call('Page.navigate', url=BASE + '/locale/' + locale)
    wait('document.documentElement.lang.startsWith(' + json.dumps(locale) + ')')
    for path in ['/admin/system-operations', '/admin/system-diagnostics', '/admin/control-chart-baselines', '/admin/analysis-methodology']:
        navigate(path)
        state = evaluate('JSON.stringify({lang:document.documentElement.lang,dir:document.documentElement.dir,title:document.title,text:document.body.innerText,indicators:document.querySelectorAll("[data-system-health-indicator]").length,overflow:document.documentElement.scrollWidth > innerWidth + 2})')
        state = json.loads(state)
        assert state['dir'] == direction, state
        assert state['indicators'] == 3, state
        assert 'monitoring.operations.' not in state['text'] and 'diagnostics.fields.' not in state['text'], state
        assert not state['overflow'], state
        if path.endswith('system-operations'):
            assert label in state['text'], state
            shot = call('Page.captureScreenshot', format='png')['data']
            destination = ROOT / 'docs' / 'screenshots' / ('remediation-operations-' + locale + '.png')
            destination.write_bytes(base64.b64decode(shot))
        results.append({k:v for k,v in state.items() if k != 'text'} | {'path': path})
assert not errors, errors
Path('/tmp/remediation-browser-results.json').write_text(json.dumps({'pages': results, 'javascript_exceptions': errors}, ensure_ascii=False, indent=2))
ws.close()
print(json.dumps({'pages_passed': len(results), 'locales': ['en','ar'], 'javascript_exceptions': len(errors)}))
