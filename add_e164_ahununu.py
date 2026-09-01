"""Patch ahununu dialplan: mobile + landline outbound (+251 / 251 / 00251 / 0…)."""
import re
import sys
from pathlib import Path

p = Path(sys.argv[1] if len(sys.argv) > 1 else "/etc/freeswitch/dialplan/01_skykin_ahununu.xml")
text = p.read_text()
changed = False

normalize_mobile = """    <extension name="skykin_outbound_et_normalize_mobile">
      <condition field="destination_number" expression="^\\+?(?:00251|251)(9[0-9]{8})$">
        <action application="transfer" data="$1 XML ahununu"/>
      </condition>
    </extension>
"""

normalize_land = """    <extension name="skykin_outbound_et_normalize_land">
      <condition field="destination_number" expression="^\\+?(?:00251|251)([1-8][0-9]{8})$">
        <action application="transfer" data="0$1 XML ahununu"/>
      </condition>
    </extension>
"""

land_bridge = """    <extension name="skykin_outbound_et_land">
      <condition field="destination_number" expression="^([1-8][0-9]{8})$">
        <action application="export" data="rtp_advertise_ip=${AHUNUNU_EXTIP}"/>
        <action application="set" data="hangup_after_bridge=true"/>
        <action application="set" data="call_direction=outbound"/>
        <action application="set" data="continue_on_fail=true"/>
        <action application="pre_answer"/>
        <action application="set" data="bleg_uuid=${create_uuid()}"/>
        <action application="set" data="continue_on_fail=false"/>
        <action application="bridge" data="{origination_uuid=${bleg_uuid},absolute_codec_string=^^:PCMA:PCMU:AMR@8000h@20i,amr_octet_align=1,rtcp=-1,rtp_secure_media=false,media_webrtc=false,ignore_early_media=false,rtp_advertise_ip=${AHUNUNU_LAN},include_external_ip=false,origination_caller_id_number=+251116198035,origination_caller_id_name=+251116198035}sofia/gateway/SIP8035/+251$1"/>
        <action application="hangup"/>
      </condition>
    </extension>
"""

e164 = """    <extension name="skykin_outbound_et_e164">
      <condition field="destination_number" expression="^\\+?(?:00251|251)([0-9]{9})$">
        <action application="export" data="rtp_advertise_ip=${AHUNUNU_EXTIP}"/>
        <action application="set" data="hangup_after_bridge=true"/>
        <action application="set" data="call_direction=outbound"/>
        <action application="set" data="continue_on_fail=true"/>
        <action application="pre_answer"/>
        <action application="set" data="bleg_uuid=${create_uuid()}"/>
        <action application="set" data="continue_on_fail=false"/>
        <action application="bridge" data="{origination_uuid=${bleg_uuid},absolute_codec_string=^^:PCMA:PCMU:AMR@8000h@20i,amr_octet_align=1,rtcp=-1,rtp_secure_media=false,media_webrtc=false,ignore_early_media=false,rtp_advertise_ip=${AHUNUNU_LAN},include_external_ip=false,origination_caller_id_number=+251116198035,origination_caller_id_name=+251116198035}sofia/gateway/SIP8035/+251$1"/>
        <action application="hangup"/>
      </condition>
    </extension>
"""

# Remove old single normalize block if present
new_text, n = re.subn(
    r'\s*<extension name="skykin_outbound_et_normalize">.*?</extension>\s*',
    '\n',
    text,
    count=1,
    flags=re.DOTALL,
)
if n:
    text = new_text
    changed = True

anchor = '    <extension name="skykin_outbound_758_zero">'
if 'skykin_outbound_et_normalize_mobile' not in text and anchor in text:
    text = text.replace(anchor, normalize_mobile + normalize_land + anchor, 1)
    changed = True
    print("added normalize mobile + land")

if 'skykin_outbound_et_land' not in text:
    ins = '    <extension name="skykin_outbound_et_e164">'
    if ins in text:
        text = text.replace(ins, land_bridge + ins, 1)
        changed = True
        print("added skykin_outbound_et_land")
    elif 'skykin_outbound_et_e164' not in text:
        text = text.replace("  </context>\n</include>", land_bridge + e164 + "  </context>\n</include>", 1)
        changed = True
        print("added skykin_outbound_et_land + et_e164")
elif 'skykin_outbound_et_e164' not in text:
    text = text.replace("  </context>\n</include>", e164 + "  </context>\n</include>", 1)
    changed = True
    print("added skykin_outbound_et_e164")
else:
    new_text, n = re.subn(
        r'<extension name="skykin_outbound_et_e164">.*?</extension>\s*',
        e164,
        text,
        count=1,
        flags=re.DOTALL,
    )
    if n:
        text = new_text
        changed = True
        print("updated skykin_outbound_et_e164 (mobile + landline)")

if changed:
    p.write_text(text)
    print("dialplan saved — run: fs_cli -x reloadxml")
else:
    print("no changes needed")
