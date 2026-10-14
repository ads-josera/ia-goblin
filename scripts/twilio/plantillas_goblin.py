#!/usr/bin/env python3
"""
Creates Goblin's WhatsApp lead-notice templates in Twilio and submits them to
WhatsApp (Meta) for approval. One template per area: Ventas, Soporte,
Facturación. Standard library only (runs on the team's Mac).

    python3 scripts/twilio/plantillas_goblin.py            # create + submit
    python3 scripts/twilio/plantillas_goblin.py --estado   # approval status

Asks for the Twilio Account SID and Auth Token without echoing them; nothing
is stored. Idempotent: a template whose friendly name already exists is not
created again (WhatsApp does not accept a rejected name twice either).

Each bot of an area then gets its template SID (HX...) in "Plantilla de aviso
(Template SID)" and this mapping in "Variables de la plantilla":

    1=event
    2=lead_id
    3=contact
    4=phone
    5=email
    6=summary
    7=conversation_url

See docs/bots/goblin/README.md ("Plantillas de aviso").
"""

import base64
import getpass
import json
import sys
import urllib.error
import urllib.request

API = 'https://content.twilio.com'

# Meta rules: category UTILITY, sequential variables with samples, enough
# fixed text per variable, never starting or ending with a variable. One
# line per variable value (the module joins summaries with " | ").
BODY = (
    'Goblin Creative · {area}\n'
    'Aviso: {{{{1}}}} · Folio {{{{2}}}}\n'
    '\n'
    'Contacto: {{{{3}}}}\n'
    'Teléfono: {{{{4}}}}\n'
    'Correo: {{{{5}}}}\n'
    '\n'
    'Resumen: {{{{6}}}}\n'
    '\n'
    'Ver la conversación: {{{{7}}}}\n'
    '\n'
    'Atiende a este cliente lo antes posible. '
    'Mensaje automático del asistente de Goblin Creative.'
)

SAMPLES = {
    '1': 'Nuevo lead',
    '2': '125',
    '3': 'Ana Ruiz',
    '4': '+52 55 1234 5678',
    '5': 'ana@ejemplo.com',
    '6': 'Solicita una tienda en línea para vender ropa; empieza desde cero.',
    '7': 'https://ia.goblincreative.com/admin/content/ai-whatsapp/conversations/125',
}

TEMPLATES = [
    ('goblin_aviso_lead_ventas', 'Ventas'),
    ('goblin_aviso_lead_soporte', 'Soporte técnico'),
    ('goblin_aviso_lead_facturacion', 'Facturación y atención comercial'),
]


def request(method, path, auth, payload=None):
    data = json.dumps(payload).encode('utf-8') if payload is not None else None
    req = urllib.request.Request(API + path, data=data, method=method)
    req.add_header('Authorization', 'Basic ' + auth)
    if data is not None:
        req.add_header('Content-Type', 'application/json')
    try:
        with urllib.request.urlopen(req, timeout=30) as response:
            return json.loads(response.read().decode('utf-8') or '{}')
    except urllib.error.HTTPError as error:
        detail = error.read().decode('utf-8', 'replace')
        sys.exit(f'Twilio respondió {error.code} en {method} {path}: {detail}')


def existing(auth):
    found = {}
    path = '/v1/Content?PageSize=500'
    while path:
        page = request('GET', path, auth)
        for content in page.get('contents', []):
            found[content.get('friendly_name')] = content.get('sid')
        next_url = (page.get('meta') or {}).get('next_page_url')
        path = next_url.replace(API, '') if next_url else None
    return found


def main():
    status_only = '--estado' in sys.argv
    sid = input('Twilio Account SID (AC...): ').strip()
    token = getpass.getpass('Twilio Auth Token (no se muestra): ').strip()
    if not sid.startswith('AC') or not token:
        sys.exit('Faltan credenciales.')
    auth = base64.b64encode(f'{sid}:{token}'.encode()).decode()

    found = existing(auth)
    if status_only:
        for name, area in TEMPLATES:
            if name not in found:
                print(f'{area:34} no existe')
                continue
            approval = request('GET', f'/v1/Content/{found[name]}/ApprovalRequests', auth).get('whatsapp') or {}
            reason = approval.get('rejection_reason') or ''
            print(f'{area:34} {found[name]}  {approval.get("status", "sin enviar"):10} {reason}')
        return

    print('\nSe crearán y enviarán a revisión (categoría UTILITY, idioma es):\n')
    print(BODY.format(area='<área>'))
    print('\nPlantillas:')
    for name, area in TEMPLATES:
        print(f'  {name:32} {area:34} {"YA EXISTE: " + found[name] if name in found else "nueva"}')
    if input('\n¿Continuar? (escribe si): ').strip().lower() not in ('si', 'sí', 's'):
        sys.exit('Cancelado.')

    for name, area in TEMPLATES:
        if name in found:
            print(f'{area:34} {found[name]}  (ya existía, no se tocó)')
            continue
        content = request('POST', '/v1/Content', auth, {
            'friendly_name': name,
            'language': 'es',
            'variables': SAMPLES,
            'types': {'twilio/text': {'body': BODY.format(area=area)}},
        })
        approval = request('POST', f'/v1/Content/{content["sid"]}/ApprovalRequests/whatsapp', auth, {
            'name': name,
            'category': 'UTILITY',
        })
        print(f'{area:34} {content["sid"]}  revisión: {approval.get("status")}')

    print('\nPega aquí los códigos HX... para cargarlos en cada bot. '
          'Estado de la revisión: python3 scripts/twilio/plantillas_goblin.py --estado')


if __name__ == '__main__':
    main()
