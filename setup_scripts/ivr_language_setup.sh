#!/bin/bash
# SkyKin Technologies - IVR Language Routing + Skill-Based Queue Setup
# Run this on the FusionPBX VM as root: bash /tmp/skykin_ivr_setup.sh
# ============================================================
# Architecture:
#   9000 → IVR Menu (Language Selection)
#       Press 1 → Amharic Queue (8001)
#       Press 2 → English Queue (8002)
#       Press 3 → Oromo Queue (8003)
#       Press 0 → General Queue (8000 - existing)
#   Each queue rings agents tagged with that language skill
# ============================================================

DOMAIN="client1.skykin.local"
PSQL="psql -U fusionpbx fusionpbx -t -c"

echo "=== SkyKin IVR + Language Queue Setup ==="
echo "Domain: $DOMAIN"

# ── Step 1: Create Language Queues in FreeSWITCH ──────────────────────────
echo ""
echo "[1/6] Creating language call center queues..."

for QUEUE_EXT in 8001 8002 8003; do
    case $QUEUE_EXT in
        8001) LANG="Amharic" ;;
        8002) LANG="English" ;;
        8003) LANG="Oromo"   ;;
    esac
    QUEUE_NAME="SkyKin ${LANG} Queue"
    QUEUE_FULL="${QUEUE_EXT}@${DOMAIN}"

    # Check if queue exists in FusionPBX DB
    EXISTING=$($PSQL "SELECT COUNT(*) FROM v_call_center_queues WHERE queue_extension='${QUEUE_EXT}' AND domain_name='${DOMAIN}'" 2>/dev/null | tr -d ' ')

    if [ "$EXISTING" = "0" ] || [ -z "$EXISTING" ]; then
        UUID=$(cat /proc/sys/kernel/random/uuid)
        $PSQL "INSERT INTO v_call_center_queues (
            call_center_queue_uuid, domain_uuid, domain_name,
            queue_name, queue_extension, queue_strategy,
            queue_moh_sound, queue_record_template,
            queue_time_base_score, queue_max_wait_time,
            queue_max_wait_time_with_no_agent, queue_max_wait_time_with_no_agent_time_reached,
            queue_announce_sound, queue_announce_frequency,
            queue_announce_position, queue_announce_no_agent,
            queue_description, queue_enabled
        ) VALUES (
            '${UUID}',
            (SELECT domain_uuid FROM v_domains WHERE domain_name='${DOMAIN}' LIMIT 1),
            '${DOMAIN}',
            '${QUEUE_NAME}', '${QUEUE_EXT}', 'ring-all',
            'local_stream://moh', '\${base_dir}/recordings/\${domain_name}/\${strftime(%Y-%m-%d %H:%M:%S)}_\${uuid}',
            'system', 0, 0, 5,
            '', 0, 'false', 'false',
            'Language: ${LANG}', 'true'
        );" 2>/dev/null
        echo "  Created queue ${QUEUE_FULL} (${LANG})"
    else
        echo "  Queue ${QUEUE_FULL} already exists — skipping"
    fi
done

# ── Step 2: Reload call center module ─────────────────────────────────────
echo ""
echo "[2/6] Reloading mod_callcenter..."
fs_cli -x "reload mod_callcenter" 2>/dev/null && echo "  Done"

# ── Step 3: Add language skill tags to agents ──────────────────────────────
echo ""
echo "[3/6] Setting up skill-based agent-to-queue tiers..."

# NOTE: This links agents to queues based on their language skill.
# Adjust AGENT_EXT and LANG mappings to match your actual agent extensions.
# Format: extension:language_queue_ext

AGENT_SKILLS=(
    "101:8001:Amharic"
    "101:8002:English"
    "102:8002:English"
    "102:8003:Oromo"
)

for SKILL in "${AGENT_SKILLS[@]}"; do
    EXT=$(echo $SKILL | cut -d: -f1)
    QEXT=$(echo $SKILL | cut -d: -f2)
    LANG=$(echo $SKILL | cut -d: -f3)
    AGENT_NAME="${EXT}@${DOMAIN}"
    QUEUE_FULL="${QEXT}@${DOMAIN}"

    # Add agent if not in callcenter
    AGENT_EXISTS=$(fs_cli -x "callcenter_config agent list" 2>/dev/null | grep -c "$AGENT_NAME")
    if [ "$AGENT_EXISTS" -eq "0" ]; then
        fs_cli -x "callcenter_config agent add ${AGENT_NAME} callback" 2>/dev/null
    fi
    fs_cli -x "callcenter_config agent set status ${AGENT_NAME} Available" 2>/dev/null

    # Add tier
    fs_cli -x "callcenter_config tier add ${QUEUE_FULL} ${AGENT_NAME} 1 1" 2>/dev/null
    echo "  Linked agent ${EXT} to ${LANG} queue (${QUEUE_FULL})"
done

# ── Step 4: Create IVR dialplan in FusionPBX DB ───────────────────────────
echo ""
echo "[4/6] Creating IVR dialplan for extension 9000..."

# Check for existing dialplan
EXISTING_IVR=$($PSQL "SELECT COUNT(*) FROM v_dialplans WHERE dialplan_number='9000' AND domain_name='${DOMAIN}'" 2>/dev/null | tr -d ' ')

if [ "$EXISTING_IVR" = "0" ] || [ -z "$EXISTING_IVR" ]; then
    DIALPLAN_UUID=$(cat /proc/sys/kernel/random/uuid)
    DOMAIN_UUID=$($PSQL "SELECT domain_uuid FROM v_domains WHERE domain_name='${DOMAIN}' LIMIT 1" | tr -d ' ')

    $PSQL "INSERT INTO v_dialplans (
        dialplan_uuid, app_uuid, domain_uuid, domain_name,
        dialplan_name, dialplan_number, dialplan_context,
        dialplan_continue, dialplan_order, dialplan_enabled,
        dialplan_description
    ) VALUES (
        '${DIALPLAN_UUID}',
        '4b821450-604a-427b-8aba-e1736baa23b2',
        '${DOMAIN_UUID}', '${DOMAIN}',
        'SkyKin Language IVR', '9000', '${DOMAIN}',
        'false', 100, 'true',
        'Language selection IVR - routes to Amharic/English/Oromo queues'
    );" 2>/dev/null

    # Add dialplan details (actions)
    ORDER=10
    for ACTION_DATA in \
        "answer||" \
        "sleep|1000|" \
        "ivr|SkyKin Language IVR|"; do
        ACTION=$(echo $ACTION_DATA | cut -d'|' -f1)
        DATA=$(echo $ACTION_DATA | cut -d'|' -f2)
        DETAIL_UUID=$(cat /proc/sys/kernel/random/uuid)
        $PSQL "INSERT INTO v_dialplan_details (
            dialplan_detail_uuid, dialplan_uuid, dialplan_detail_tag,
            dialplan_detail_type, dialplan_detail_data, dialplan_detail_order
        ) VALUES (
            '${DETAIL_UUID}', '${DIALPLAN_UUID}',
            'action', '${ACTION}', '${DATA}', ${ORDER}
        );" 2>/dev/null
        ORDER=$((ORDER+10))
    done
    echo "  Dialplan created (UUID: ${DIALPLAN_UUID})"
else
    echo "  Dialplan for 9000 already exists — skipping"
fi

# ── Step 5: Create FusionPBX IVR menu ─────────────────────────────────────
echo ""
echo "[5/6] Creating IVR menu 'SkyKin Language IVR'..."

IVR_EXISTS=$($PSQL "SELECT COUNT(*) FROM v_ivr_menus WHERE ivr_menu_name='SkyKin Language IVR' AND domain_name='${DOMAIN}'" 2>/dev/null | tr -d ' ')

if [ "$IVR_EXISTS" = "0" ] || [ -z "$IVR_EXISTS" ]; then
    IVR_UUID=$(cat /proc/sys/kernel/random/uuid)
    DOMAIN_UUID=$($PSQL "SELECT domain_uuid FROM v_domains WHERE domain_name='${DOMAIN}' LIMIT 1" | tr -d ' ')

    $PSQL "INSERT INTO v_ivr_menus (
        ivr_menu_uuid, domain_uuid, domain_name,
        ivr_menu_name, ivr_menu_extension, ivr_menu_greet_long,
        ivr_menu_greet_short, ivr_menu_invalid_sound,
        ivr_menu_exit_sound, ivr_menu_timeout,
        ivr_menu_exit_app, ivr_menu_exit_data,
        ivr_menu_max_failures, ivr_menu_max_timeouts,
        ivr_menu_digit_len, ivr_menu_enabled, ivr_menu_description
    ) VALUES (
        '${IVR_UUID}', '${DOMAIN_UUID}', '${DOMAIN}',
        'SkyKin Language IVR', '9000',
        '/var/www/fusionpbx/ivr_greeting.wav',
        '/var/www/fusionpbx/ivr_greeting_short.wav',
        'ivr/ivr-that_was_an_invalid_entry.wav',
        'voicemail/vm-goodbye.wav',
        '5000', 'hangup', '',
        3, 3, 1, 'true',
        'Language selection menu for SkyKin contact center'
    );" 2>/dev/null

    # IVR options: 1=Amharic(8001), 2=English(8002), 3=Oromo(8003), 0=General(8000)
    declare -A IVR_OPTIONS=(
        ["1"]="8001"
        ["2"]="8002"
        ["3"]="8003"
        ["0"]="8000"
    )
    OPT_ORDER=10
    for DIGIT in "${!IVR_OPTIONS[@]}"; do
        DEST_EXT="${IVR_OPTIONS[$DIGIT]}"
        OPT_UUID=$(cat /proc/sys/kernel/random/uuid)
        $PSQL "INSERT INTO v_ivr_menu_options (
            ivr_menu_option_uuid, ivr_menu_uuid, domain_uuid,
            ivr_menu_option_digits, ivr_menu_option_action,
            ivr_menu_option_param, ivr_menu_option_order, ivr_menu_option_description
        ) VALUES (
            '${OPT_UUID}', '${IVR_UUID}', '${DOMAIN_UUID}',
            '${DIGIT}', 'menu-exec-app',
            'transfer ${DEST_EXT} XML ${DOMAIN}',
            ${OPT_ORDER}, 'Press ${DIGIT} to route to queue ${DEST_EXT}'
        );" 2>/dev/null
        echo "  Option ${DIGIT} → Queue ${DEST_EXT}"
        OPT_ORDER=$((OPT_ORDER+10))
    done
    echo "  IVR menu created (UUID: ${IVR_UUID})"
else
    echo "  IVR menu already exists — skipping"
fi

# ── Step 6: Reload XML ─────────────────────────────────────────────────────
echo ""
echo "[6/6] Reloading FreeSWITCH XML config..."
fs_cli -x "reloadxml" 2>/dev/null && echo "  Done"
fs_cli -x "reload mod_callcenter" 2>/dev/null

echo ""
echo "=== Setup Complete ==="
echo ""
echo "NEXT STEPS:"
echo "1. Upload IVR greeting audio in FusionPBX > IVR > SkyKin Language IVR"
echo "   - The greeting should say: 'Welcome to SkyKin. Press 1 for Amharic, 2 for English, 3 for Oromo, 0 for General Support'"
echo "2. Test by calling extension 9000 from an internal extension or external line"
echo "3. Assign agents to language queues:"
echo "   - Edit AGENT_SKILLS array in this script to match your extensions"
echo ""
echo "Queue Summary:"
echo "  9000 → IVR Language Selection"
echo "  8000 → General Queue (all agents)"
echo "  8001 → Amharic Queue (Ext 101)"
echo "  8002 → English Queue (Ext 101, 102)"
echo "  8003 → Oromo Queue (Ext 102)"
echo ""
echo "Done!"
