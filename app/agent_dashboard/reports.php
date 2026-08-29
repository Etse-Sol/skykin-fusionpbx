<?php
// SkyKin Technologies – Reports Dashboard
require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/skykin_config.php';

$is_api = isset($_GET['api']) || isset($_GET['action']);
skykin_require_groups(['superadmin', 'admin', 'supervisor'], $is_api);

$logged_in_user   = $_SESSION['username']    ?? '';
$logged_in_domain = skykin_default_domain();
$domain  = htmlspecialchars(skykin_domain_param($_GET['domain'] ?? null));
$embed   = !empty($_GET['embed']);
$today   = date('Y-m-d');

// ── DB helper ──────────────────────────────────────────────────────────────
function getDB() {
    static $db = null;
    if ($db !== null) return $db;
    $db = skykin_pdo_fusionpbx(); // throws RuntimeException on failure
    return $db;
}

function xlsxEscape($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function xlsxTextCell(string $ref, $value, int $style = 0): string {
    return '<c r="'.$ref.'" s="'.$style.'" t="inlineStr"><is><t xml:space="preserve">'
        .xlsxEscape($value).'</t></is></c>';
}

function xlsxNumberCell(string $ref, $value, int $style = 0): string {
    return '<c r="'.$ref.'" s="'.$style.'" t="n"><v>'.(float)$value.'</v></c>';
}

function xlsxColumn(int $number): string {
    $name = '';
    while ($number > 0) {
        $number--;
        $name = chr(65 + ($number % 26)).$name;
        $number = intdiv($number, 26);
    }
    return $name;
}

// ── JSON API endpoints ───────────────────────────────────────────────────────
if (isset($_GET['api'])) {
    error_reporting(0);
    header('Content-Type: application/json');
    $api    = $_GET['api'];
    $dom    = skykin_domain_param($_GET['domain'] ?? null);
    $from   = $_GET['from']    ?? date('Y-m-d', strtotime('-7 days'));
    $to     = $_GET['to']      ?? date('Y-m-d');
    $ts     = strtotime($from.' 00:00:00');
    $te     = strtotime($to.' 23:59:59');

    try {
        $db = getDB();

        // ── Daily call volume ──────────────────────────────────────────────
        if ($api === 'daily_volume') {
            $s = $db->prepare("SELECT
                to_char(to_timestamp(start_epoch),'YYYY-MM-DD') as day,
                COUNT(*) as total,
                SUM(CASE WHEN billsec>0 THEN 1 ELSE 0 END) as answered,
                SUM(CASE WHEN billsec=0 THEN 1 ELSE 0 END) as missed,
                SUM(CASE WHEN direction='inbound'  THEN 1 ELSE 0 END) as inbound,
                SUM(CASE WHEN direction='outbound' THEN 1 ELSE 0 END) as outbound,
                SUM(CASE WHEN direction='local'    THEN 1 ELSE 0 END) as local,
                ROUND(AVG(CASE WHEN billsec>0 THEN billsec ELSE NULL END)::numeric,0) as avg_dur
                FROM v_xml_cdr WHERE domain_name=:d AND start_epoch>=:ts AND start_epoch<=:te
                GROUP BY day ORDER BY day");
            $s->execute([':d'=>$dom,':ts'=>$ts,':te'=>$te]);
            echo json_encode($s->fetchAll(PDO::FETCH_ASSOC));
            exit;
        }

        // ── Hourly heatmap ────────────────────────────────────────────────
        if ($api === 'hourly_heatmap') {
            $s = $db->prepare("SELECT
                EXTRACT(DOW FROM to_timestamp(start_epoch))::int as dow,
                EXTRACT(HOUR FROM to_timestamp(start_epoch))::int as hour,
                COUNT(*) as total
                FROM v_xml_cdr WHERE domain_name=:d AND start_epoch>=:ts AND start_epoch<=:te
                GROUP BY dow, hour ORDER BY dow, hour");
            $s->execute([':d'=>$dom,':ts'=>$ts,':te'=>$te]);
            echo json_encode($s->fetchAll(PDO::FETCH_ASSOC));
            exit;
        }

        // ── Agent performance ─────────────────────────────────────────────
        if ($api === 'agent_performance') {
            // Get all extensions for this domain
            $s = $db->prepare("SELECT e.extension,
                COALESCE(e.effective_caller_id_name, e.extension) as name
                FROM v_extensions e JOIN v_domains d ON d.domain_uuid=e.domain_uuid
                WHERE d.domain_name=:d ORDER BY e.extension");
            $s->execute([':d'=>$dom]);
            $exts = $s->fetchAll(PDO::FETCH_ASSOC);
            $rows = [];
            foreach ($exts as $ex) {
                $ext = $ex['extension'];
                $s2 = $db->prepare("SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN billsec>0 THEN 1 ELSE 0 END) as answered,
                    SUM(CASE WHEN billsec=0 THEN 1 ELSE 0 END) as missed,
                    COALESCE(SUM(billsec),0) as total_talk,
                    ROUND(AVG(CASE WHEN billsec>0 THEN billsec ELSE NULL END)::numeric,0) as avg_dur,
                    SUM(CASE WHEN direction='inbound' THEN 1 ELSE 0 END) as inbound,
                    SUM(CASE WHEN direction='outbound' THEN 1 ELSE 0 END) as outbound,
                    SUM(CASE WHEN direction='local' THEN 1 ELSE 0 END) as local
                    FROM v_xml_cdr WHERE domain_name=:d
                    AND (caller_id_number=:e OR destination_number=:e)
                    AND start_epoch>=:ts AND start_epoch<=:te");
                $s2->execute([':d'=>$dom,':e'=>$ext,':ts'=>$ts,':te'=>$te]);
                $r = $s2->fetch(PDO::FETCH_ASSOC);
                $answered = (int)($r['answered'] ?? 0);
                $total    = (int)($r['total']    ?? 0);
                $rows[] = [
                    'ext'        => $ext,
                    'name'       => $ex['name'],
                    'total'      => $total,
                    'answered'   => $answered,
                    'missed'     => (int)($r['missed']     ?? 0),
                    'inbound'    => (int)($r['inbound']    ?? 0),
                    'outbound'   => (int)($r['outbound']   ?? 0),
                    'local'      => (int)($r['local']      ?? 0),
                    'total_talk' => (int)($r['total_talk'] ?? 0),
                    'avg_dur'    => (int)($r['avg_dur']    ?? 0),
                    'answer_rate'=> $total > 0 ? round($answered/$total*100,1) : 0,
                ];
            }
            // Sort by total calls desc
            usort($rows, fn($a,$b) => $b['total'] - $a['total']);
            echo json_encode($rows);
            exit;
        }

        // ── Summary KPIs ──────────────────────────────────────────────────
        if ($api === 'summary') {
            $s = $db->prepare("SELECT
                COUNT(*) as total,
                SUM(CASE WHEN billsec>0 THEN 1 ELSE 0 END) as answered,
                SUM(CASE WHEN billsec=0 THEN 1 ELSE 0 END) as missed,
                SUM(CASE WHEN direction='inbound' THEN 1 ELSE 0 END) as inbound,
                SUM(CASE WHEN direction='outbound' THEN 1 ELSE 0 END) as outbound,
                SUM(CASE WHEN direction='local' THEN 1 ELSE 0 END) as local,
                COALESCE(SUM(billsec),0) as total_talk,
                ROUND(AVG(CASE WHEN billsec>0 THEN billsec ELSE NULL END)::numeric,0) as avg_dur,
                ROUND(AVG(CASE WHEN billsec=0 THEN 1 ELSE 0 END)*100::numeric,1) as abandon_rate
                FROM v_xml_cdr WHERE domain_name=:d AND start_epoch>=:ts AND start_epoch<=:te");
            $s->execute([':d'=>$dom,':ts'=>$ts,':te'=>$te]);
            echo json_encode($s->fetch(PDO::FETCH_ASSOC));
            exit;
        }

        // ── Hourly volume ─────────────────────────────────────────────────
        if ($api === 'hourly_volume') {
            $s = $db->prepare("SELECT
                EXTRACT(HOUR FROM to_timestamp(start_epoch))::int as hour,
                COUNT(*) as total,
                SUM(CASE WHEN billsec>0 THEN 1 ELSE 0 END) as answered,
                SUM(CASE WHEN billsec=0 THEN 1 ELSE 0 END) as missed
                FROM v_xml_cdr WHERE domain_name=:d AND start_epoch>=:ts AND start_epoch<=:te
                GROUP BY hour ORDER BY hour");
            $s->execute([':d'=>$dom,':ts'=>$ts,':te'=>$te]);
            echo json_encode($s->fetchAll(PDO::FETCH_ASSOC));
            exit;
        }

        // ── Filtered call details ──────────────────────────────────────────
        if ($api === 'call_list') {
            $type = $_GET['type'] ?? 'all';
            $where = "domain_name=:d AND start_epoch>=:ts AND start_epoch<=:te";
            $params = [':d'=>$dom,':ts'=>$ts,':te'=>$te];
            if ($type === 'answered') {
                $where .= " AND billsec>0";
            } elseif ($type === 'missed') {
                $where .= " AND billsec=0";
            } elseif (in_array($type, ['inbound','outbound','local'], true)) {
                $where .= " AND direction=:dir";
                $params[':dir'] = $type;
            }
            $s = $db->prepare("SELECT
                to_char(to_timestamp(start_epoch),'YYYY-MM-DD HH24:MI') as call_time,
                caller_id_name, caller_id_number, destination_number,
                direction, duration, billsec, hangup_cause,
                CASE WHEN billsec>0 THEN 'answered' ELSE 'missed' END as result
                FROM v_xml_cdr WHERE {$where}
                ORDER BY start_epoch DESC LIMIT 500");
            $s->execute($params);
            echo json_encode($s->fetchAll(PDO::FETCH_ASSOC));
            exit;
        }

        // ── Queue SLA ─────────────────────────────────────────────────────
        if ($api === 'queue_sla') {
            $s = $db->prepare("SELECT
                destination_number as queue_num,
                COUNT(*) as total,
                SUM(CASE WHEN billsec>0 THEN 1 ELSE 0 END) as answered,
                ROUND(AVG(CASE WHEN billsec>0 THEN billsec ELSE NULL END)::numeric,0) as avg_dur
                FROM v_xml_cdr WHERE domain_name=:d
                AND start_epoch>=:ts AND start_epoch<=:te
                AND (destination_number ~ '^[89][0-9]{3}$' OR destination_number LIKE '800%')
                GROUP BY destination_number ORDER BY total DESC LIMIT 20");
            $s->execute([':d'=>$dom,':ts'=>$ts,':te'=>$te]);
            echo json_encode($s->fetchAll(PDO::FETCH_ASSOC));
            exit;
        }

        // ── Styled Excel workbook ─────────────────────────────────────────
        if ($api === 'export_excel') {
            if (!class_exists('ZipArchive')) {
                http_response_code(500);
                echo json_encode(['error'=>'Excel export requires the PHP ZIP extension']);
                exit;
            }

            $type = $_GET['type'] ?? 'all';
            $summaryStmt = $db->prepare("SELECT
                COUNT(*) as total,
                SUM(CASE WHEN billsec>0 THEN 1 ELSE 0 END) as answered,
                SUM(CASE WHEN billsec=0 THEN 1 ELSE 0 END) as missed,
                SUM(CASE WHEN direction='inbound' THEN 1 ELSE 0 END) as inbound,
                SUM(CASE WHEN direction='outbound' THEN 1 ELSE 0 END) as outbound,
                SUM(CASE WHEN direction='local' THEN 1 ELSE 0 END) as local,
                COALESCE(SUM(billsec),0) as total_talk,
                ROUND(AVG(CASE WHEN billsec>0 THEN billsec ELSE NULL END)::numeric,0) as avg_talk
                FROM v_xml_cdr
                WHERE domain_name=:d AND start_epoch>=:ts AND start_epoch<=:te");
            $summaryStmt->execute([':d'=>$dom,':ts'=>$ts,':te'=>$te]);
            $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $where = "domain_name=:d AND start_epoch>=:ts AND start_epoch<=:te";
            $params = [':d'=>$dom,':ts'=>$ts,':te'=>$te];
            if ($type === 'answered') {
                $where .= " AND billsec>0";
            } elseif ($type === 'missed') {
                $where .= " AND billsec=0";
            } elseif (in_array($type, ['inbound','outbound','local'], true)) {
                $where .= " AND direction=:dir";
                $params[':dir'] = $type;
            }
            $detailStmt = $db->prepare("SELECT
                to_char(to_timestamp(start_epoch),'YYYY-MM-DD') as call_date,
                to_char(to_timestamp(start_epoch),'HH24:MI:SS') as call_time,
                caller_id_name, caller_id_number, destination_number,
                direction,
                CASE WHEN billsec>0 THEN 'Answered' ELSE 'Missed' END as result,
                duration, billsec, hangup_cause, record_name
                FROM v_xml_cdr WHERE {$where}
                ORDER BY start_epoch DESC LIMIT 5000");
            $detailStmt->execute($params);
            $details = $detailStmt->fetchAll(PDO::FETCH_ASSOC);

            $total = (int)($summary['total'] ?? 0);
            $answered = (int)($summary['answered'] ?? 0);
            $missed = (int)($summary['missed'] ?? 0);
            $answerRate = $total ? round($answered / $total * 100, 1) : 0;
            $missedRate = $total ? round($missed / $total * 100, 1) : 0;

            $rows = [];
            $rows[] = '<row r="1" ht="30" customHeight="1">'.xlsxTextCell('A1','SKY CONNECT CALL REPORT',1).'</row>';
            $rows[] = '<row r="2" ht="22" customHeight="1">'.xlsxTextCell(
                'A2', 'Period: '.$from.' to '.$to.'   |   Detail filter: '.ucfirst($type), 2
            ).'</row>';
            $rows[] = '<row r="3"></row>';
            $rows[] = '<row r="4" ht="22" customHeight="1">'.xlsxTextCell('A4','REPORT SUMMARY',3).'</row>';
            $rows[] = '<row r="5" ht="28" customHeight="1">'
                .xlsxTextCell('A5','Total Calls',4).xlsxNumberCell('B5',$total,5)
                .xlsxTextCell('C5','Answered',4).xlsxNumberCell('D5',$answered,5)
                .xlsxTextCell('E5','Missed',4).xlsxNumberCell('F5',$missed,5)
                .xlsxTextCell('G5','Answer Rate',4).xlsxTextCell('H5',$answerRate.'%',5)
                .xlsxTextCell('I5','Missed Rate',4).xlsxTextCell('J5',$missedRate.'%',5)
                .'</row>';
            $rows[] = '<row r="6" ht="28" customHeight="1">'
                .xlsxTextCell('A6','Inbound',4).xlsxNumberCell('B6',(int)($summary['inbound'] ?? 0),5)
                .xlsxTextCell('C6','Outbound',4).xlsxNumberCell('D6',(int)($summary['outbound'] ?? 0),5)
                .xlsxTextCell('E6','Local',4).xlsxNumberCell('F6',(int)($summary['local'] ?? 0),5)
                .xlsxTextCell('G6','Average Talk',4).xlsxTextCell('H6',(int)($summary['avg_talk'] ?? 0).' seconds',5)
                .xlsxTextCell('I6','Total Talk',4).xlsxTextCell('J6',(int)($summary['total_talk'] ?? 0).' seconds',5)
                .'</row>';
            $rows[] = '<row r="7"></row>';
            $rows[] = '<row r="8" ht="22" customHeight="1">'.xlsxTextCell('A8','CALL DETAILS — '.strtoupper($type),3).'</row>';

            $headers = ['Date','Time','Caller Name','Caller Number','Destination','Direction',
                'Result','Duration (s)','Talk Time (s)','Hangup Cause','Recording'];
            $headerCells = '';
            foreach ($headers as $i => $header) {
                $headerCells .= xlsxTextCell(xlsxColumn($i+1).'9', $header, 6);
            }
            // Compact two-line headings with room for Excel's filter buttons.
            $rows[] = '<row r="9" ht="30" customHeight="1">'.$headerCells.'</row>';

            $rowNum = 10;
            foreach ($details as $detail) {
                $values = [
                    $detail['call_date'], $detail['call_time'], $detail['caller_id_name'],
                    $detail['caller_id_number'], $detail['destination_number'], $detail['direction'],
                    $detail['result'], (int)$detail['duration'], (int)$detail['billsec'],
                    $detail['hangup_cause'], $detail['record_name'],
                ];
                $style = ($rowNum % 2 === 0) ? 7 : 8;
                $cells = '';
                foreach ($values as $i => $value) {
                    $cellStyle = ($i === 6)
                        ? (strtolower((string)$value) === 'answered' ? 9 : 10)
                        : $style;
                    $ref = xlsxColumn($i+1).$rowNum;
                    $cells .= in_array($i, [7,8], true)
                        ? xlsxNumberCell($ref, $value, $cellStyle)
                        : xlsxTextCell($ref, $value, $cellStyle);
                }
                $rows[] = '<row r="'.$rowNum.'" ht="21" customHeight="1">'.$cells.'</row>';
                $rowNum++;
            }
            $lastRow = max(9, $rowNum - 1);

            $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                .'<dimension ref="A1:K'.$lastRow.'"/>'
                .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="9" topLeftCell="A10" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
                .'<sheetFormatPr defaultRowHeight="18"/>'
                .'<cols>'
                .'<col min="1" max="1" width="14" customWidth="1"/><col min="2" max="2" width="12" customWidth="1"/>'
                .'<col min="3" max="3" width="22" customWidth="1"/><col min="4" max="4" width="17" customWidth="1"/>'
                .'<col min="5" max="5" width="16" customWidth="1"/><col min="6" max="6" width="14" customWidth="1"/>'
                .'<col min="7" max="7" width="13" customWidth="1"/><col min="8" max="9" width="14" customWidth="1"/>'
                .'<col min="10" max="10" width="26" customWidth="1"/><col min="11" max="11" width="34" customWidth="1"/>'
                .'</cols><sheetData>'.implode('', $rows).'</sheetData>'
                // OOXML requires autoFilter before mergeCells; reversing them
                // makes Excel offer to repair the file on open.
                .'<autoFilter ref="A9:K'.$lastRow.'"/>'
                .'<mergeCells count="3"><mergeCell ref="A1:K1"/><mergeCell ref="A2:K2"/><mergeCell ref="A4:K4"/></mergeCells>'
                .'<pageMargins left="0.3" right="0.3" top="0.5" bottom="0.5" header="0.2" footer="0.2"/>'
                .'</worksheet>';

            $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                .'<fonts count="4">'
                .'<font><sz val="11"/><name val="Calibri"/></font>'
                .'<font><b/><sz val="11"/><name val="Calibri"/></font>'
                .'<font><b/><color rgb="FFFFFFFF"/><sz val="16"/><name val="Calibri"/></font>'
                .'<font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Calibri"/></font>'
                .'</fonts>'
                .'<fills count="7">'
                .'<fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
                .'<fill><patternFill patternType="solid"><fgColor rgb="FF0047AB"/><bgColor indexed="64"/></patternFill></fill>'
                .'<fill><patternFill patternType="solid"><fgColor rgb="FFDCEBFF"/><bgColor indexed="64"/></patternFill></fill>'
                .'<fill><patternFill patternType="solid"><fgColor rgb="FFE8F5E9"/><bgColor indexed="64"/></patternFill></fill>'
                .'<fill><patternFill patternType="solid"><fgColor rgb="FFFDECEC"/><bgColor indexed="64"/></patternFill></fill>'
                .'<fill><patternFill patternType="solid"><fgColor rgb="FFF6F8FB"/><bgColor indexed="64"/></patternFill></fill>'
                .'</fills>'
                .'<borders count="2"><border/><border><left style="thin"><color rgb="FFDDE3EA"/></left>'
                .'<right style="thin"><color rgb="FFDDE3EA"/></right><top style="thin"><color rgb="FFDDE3EA"/></top>'
                .'<bottom style="thin"><color rgb="FFDDE3EA"/></bottom></border></borders>'
                .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
                .'<cellXfs count="11">'
                .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
                .'<xf numFmtId="0" fontId="2" fillId="2" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
                .'<xf numFmtId="0" fontId="1" fillId="3" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="center"/></xf>'
                .'<xf numFmtId="0" fontId="1" fillId="3" borderId="0" xfId="0"/>'
                .'<xf numFmtId="0" fontId="1" fillId="6" borderId="1" xfId="0"/>'
                .'<xf numFmtId="0" fontId="1" fillId="0" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center"/></xf>'
                .'<xf numFmtId="0" fontId="3" fillId="2" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
                .'<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0"/>'
                .'<xf numFmtId="0" fontId="0" fillId="6" borderId="1" xfId="0"/>'
                .'<xf numFmtId="0" fontId="1" fillId="4" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center"/></xf>'
                .'<xf numFmtId="0" fontId="1" fillId="5" borderId="1" xfId="0" applyAlignment="1"><alignment horizontal="center"/></xf>'
                .'</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
                .'</styleSheet>';

            $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
                .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                .'<sheets><sheet name="Call Report" sheetId="1" r:id="rId1"/></sheets></workbook>';

            $tmp = tempnam(sys_get_temp_dir(), 'skykin_xlsx_');
            $zip = new ZipArchive();
            if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
                http_response_code(500);
                echo json_encode(['error'=>'Could not create Excel report']);
                exit;
            }
            $zip->addFromString('[Content_Types].xml',
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                .'<Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                .'</Types>');
            $zip->addFromString('_rels/.rels',
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                .'</Relationships>');
            $zip->addFromString('xl/workbook.xml', $workbookXml);
            $zip->addFromString('xl/_rels/workbook.xml.rels',
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                .'</Relationships>');
            $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
            $zip->addFromString('xl/styles.xml', $stylesXml);
            $zip->close();

            while (ob_get_level() > 0) ob_end_clean();
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="skykin_'.$type.'_'.date('Ymd').'.xlsx"');
            header('Content-Length: '.filesize($tmp));
            header('Cache-Control: private, no-store');
            readfile($tmp);
            unlink($tmp);
            exit;
        }

    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sky Connect – Reports</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Segoe UI',sans-serif;background:#f0f2f5;color:#333;min-height:100vh}

/* ─── Top Nav ─────────────────────────────── */
.topbar{background:linear-gradient(135deg,#0047AB,#00B4D8);border-bottom:1px solid #e0e0e0;padding:0 24px;height:56px;
  display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100}
.topbar-left{display:flex;align-items:center;gap:20px}
.brand{font-weight:700;font-size:17px;color:#fff;letter-spacing:.5px}
.brand span{color:rgba(255,255,255,.8);font-weight:400}
.sk-footer{text-align:center;font-size:11px;color:#aaa;padding:20px 24px}
.nav-links{display:flex;gap:4px}
.nav-links a{color:rgba(255,255,255,.75);text-decoration:none;padding:6px 14px;border-radius:6px;font-size:13px;transition:.2s}
.nav-links a:hover,.nav-links a.active{background:rgba(255,255,255,.2);color:#fff}
.topbar-right{display:flex;align-items:center;gap:12px;font-size:13px;color:rgba(255,255,255,.8)}
.user-pill{background:rgba(255,255,255,.15);padding:5px 12px;border-radius:20px;color:#fff;font-size:12px}

/* ─── Filters ─────────────────────────────── */
.filters{background:#ffffff;border-bottom:1px solid #e0e0e0;padding:12px 24px;
  display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.filters label{font-size:12px;color:#888}
.filters input,.filters select{background:#f0f2f5;border:1px solid #e0e0e0;color:#333;
  padding:6px 10px;border-radius:6px;font-size:13px}
.filters input:focus,.filters select:focus{outline:none;border-color:#58a6ff}
.btn-filter{background:#238636;color:#fff;border:none;padding:7px 16px;border-radius:6px;
  cursor:pointer;font-size:13px;font-weight:500}
.btn-filter:hover{background:#2ea043}
.btn-export{background:#f0f2f5;color:#58a6ff;border:1px solid #e0e0e0;padding:7px 14px;
  border-radius:6px;cursor:pointer;font-size:13px}
.btn-export:hover{background:#e0e0e0}
.range-presets{display:flex;gap:6px}
.preset-btn{background:#f0f2f5;border:1px solid #e0e0e0;color:#888;padding:5px 10px;
  border-radius:6px;cursor:pointer;font-size:12px}
.preset-btn:hover,.preset-btn.active{background:#388bfd22;border-color:#58a6ff;color:#58a6ff}

/* ─── Page layout ─────────────────────────── */
.page{padding:20px 24px;max-width:1400px;margin:0 auto}

/* ─── KPI cards ───────────────────────────── */
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:20px}
.kpi-card{min-height:92px;background:#fff;border:1px solid #edf0f5;border-top:3px solid #0047AB;
  border-radius:10px;padding:14px 16px;box-shadow:0 1px 5px rgba(0,0,0,.05);
  display:flex;flex-direction:column;justify-content:center}
.kpi-value{font-size:25px;font-weight:700;line-height:1;color:#0047AB}
.kpi-label{order:2;font-size:11px;color:#777;margin-top:7px}
.kpi-sub{order:3;font-size:10px;color:#999;margin-top:3px}
.kpi-card.green{border-top-color:#28a745}.kpi-card.green .kpi-value{color:#28a745}
.kpi-card.red{border-top-color:#dc3545}.kpi-card.red .kpi-value{color:#dc3545}
.kpi-card.blue{border-top-color:#0047AB}.kpi-card.blue .kpi-value{color:#0047AB}
.kpi-card.yellow{border-top-color:#fd7e14}.kpi-card.yellow .kpi-value{color:#e65100}

/* ─── Chart cards ─────────────────────────── */
.chart-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px}
.chart-card{background:#ffffff;border:1px solid #e0e0e0;border-radius:10px;padding:20px}
.chart-card.full{grid-column:1/-1}
.chart-title{font-size:13px;font-weight:600;color:#333;margin-bottom:16px}
.chart-wrap{position:relative;height:240px}

/* ─── Agent table ─────────────────────────── */
.section-title{font-size:14px;font-weight:600;color:#333;margin-bottom:12px}
.data-table{width:100%;border-collapse:collapse;font-size:13px}
.data-table th{background:#f0f2f5;color:#888;padding:10px 12px;text-align:left;
  font-weight:500;font-size:11px;text-transform:uppercase;letter-spacing:.5px}
.data-table td{padding:10px 12px;border-bottom:1px solid #21262d;color:#333;vertical-align:middle}
.data-table tr:hover td{background:#f0f2f522}
.data-table tr:last-child td{border-bottom:none}
.bar-cell{display:flex;align-items:center;gap:8px}
.bar-bg{flex:1;background:#f0f2f5;border-radius:4px;height:6px;min-width:60px}
.bar-fill{height:6px;border-radius:4px;background:#58a6ff;transition:.5s}
.bar-fill.green{background:#3fb950}
.rank-badge{width:24px;height:24px;border-radius:50%;background:#f0f2f5;color:#888;
  font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center}
.rank-badge.gold{background:#d29922;color:#0d1117}
.rank-badge.silver{background:#8b949e;color:#0d1117}
.rank-badge.bronze{background:#bf8700;color:#0d1117}
.answer-rate{font-size:12px;font-weight:600}
.answer-rate.good{color:#3fb950}
.answer-rate.ok{color:#d29922}
.answer-rate.bad{color:#f85149}

/* ─── Queue table ─────────────────────────── */
.queue-table-wrap{background:#ffffff;border:1px solid #e0e0e0;border-radius:10px;padding:20px;margin-bottom:20px}

/* ─── Loading ─────────────────────────────── */
.loading-overlay{display:flex;align-items:center;justify-content:center;height:80px;color:#888;font-size:13px}
.detail-tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px}
.detail-tab{background:#f0f2f5;border:1px solid #e0e0e0;color:#666;padding:7px 13px;
  border-radius:7px;cursor:pointer;font-size:12px}
.detail-tab.active,.detail-tab:hover{background:#388bfd22;border-color:#58a6ff;color:#2563eb}
.detail-table-wrap{max-height:430px;overflow:auto}
.result-badge{padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700}
.result-badge.answered{background:#dcfce7;color:#15803d}
.result-badge.missed{background:#fee2e2;color:#b91c1c}

body.embed-mode{background:#f0f2f5}
body.embed-mode .topbar{display:none}
body.embed-mode .sk-footer{display:none}
body.embed-mode .page{padding-top:12px}
</style>
</head>
<body<?php echo $embed ? ' class="embed-mode"' : ''; ?>>

<?php if (!$embed): ?>
<div class="topbar">
  <div class="topbar-left">
    <div class="brand">Sky <span>Connect</span></div>
    <nav class="nav-links">
      <a href="/app/agent_dashboard/supervisor.php">Supervisor</a>
      <a href="/app/agent_dashboard/reports.php" class="active">Reports</a>
      <a href="/app/agent_dashboard/evaluation.php">Evaluation</a>
      <a href="/app/agent_dashboard/crm.php">CRM</a>
      <a href="/app/agent_dashboard/billing.php">Billing</a>
      <a href="/app/agent_dashboard/index.php">Agent View</a>
    </nav>
  </div>
  <div class="topbar-right">
    <div class="user-pill"><?php echo htmlspecialchars($logged_in_user); ?> &nbsp;|&nbsp; <?php echo htmlspecialchars($domain); ?></div>
    <a href="/logout.php" style="color:#f85149;font-size:12px;text-decoration:none">Logout</a>
  </div>
</div>
<?php endif; ?>

<!-- Filters -->
<div class="filters">
  <label>From</label>
  <input type="date" id="fFrom" value="<?php echo $today; ?>">
  <label>To</label>
  <input type="date" id="fTo" value="<?php echo $today; ?>">
  <div class="range-presets">
    <button class="preset-btn active" onclick="setRange(0,'today')">Today</button>
    <button class="preset-btn" onclick="setRange(7,'7d')">7 Days</button>
    <button class="preset-btn" onclick="setRange(30,'30d')">30 Days</button>
    <button class="preset-btn" onclick="setRange(90,'90d')">90 Days</button>
  </div>
  <button class="btn-filter" onclick="loadAll()">&#128200; Refresh</button>
  <button class="btn-export" onclick="exportExcel()">&#11015; Export Excel</button>
  <span id="loadStatus" style="font-size:12px;color:#888"></span>
</div>

<div class="page">

  <!-- KPI Summary -->
  <div class="kpi-grid" id="kpiGrid">
    <div class="kpi-card blue"><div class="kpi-label">Total Calls</div><div class="kpi-value" id="kpiTotal">—</div><div class="kpi-sub">in selected period</div></div>
    <div class="kpi-card green"><div class="kpi-label">Answered</div><div class="kpi-value" id="kpiAnswered">—</div><div class="kpi-sub" id="kpiAnswerRate">—%</div></div>
    <div class="kpi-card red"><div class="kpi-label">Missed</div><div class="kpi-value" id="kpiMissed">—</div><div class="kpi-sub" id="kpiAbandon">—% abandon</div></div>
    <div class="kpi-card"><div class="kpi-label">Inbound</div><div class="kpi-value" id="kpiInbound">—</div></div>
    <div class="kpi-card"><div class="kpi-label">Outbound</div><div class="kpi-value" id="kpiOutbound">—</div></div>
    <div class="kpi-card green"><div class="kpi-label">Local</div><div class="kpi-value" id="kpiLocal">—</div></div>
    <div class="kpi-card yellow"><div class="kpi-label">Avg Duration</div><div class="kpi-value" id="kpiAvgDur">—</div><div class="kpi-sub">seconds</div></div>
    <div class="kpi-card"><div class="kpi-label">Total Talk</div><div class="kpi-value" id="kpiTalkHrs">—</div><div class="kpi-sub">hours</div></div>
  </div>

  <!-- Charts row -->
  <div class="chart-grid">
    <div class="chart-card full">
      <div class="chart-title">Daily Call Volume</div>
      <div class="chart-wrap"><canvas id="chartVolume"></canvas></div>
    </div>
    <div class="chart-card">
      <div class="chart-title">Call Direction Split</div>
      <div class="chart-wrap"><canvas id="chartDirection"></canvas></div>
    </div>
    <div class="chart-card">
      <div class="chart-title">Answer vs Missed</div>
      <div class="chart-wrap"><canvas id="chartAM"></canvas></div>
    </div>
    <div class="chart-card full">
      <div class="chart-title">Hourly Call Volume</div>
      <div class="chart-wrap"><canvas id="chartHourly"></canvas></div>
    </div>
  </div>

  <!-- Agent Performance -->
  <div style="background:#ffffff;border:1px solid #e0e0e0;border-radius:10px;padding:20px;margin-bottom:20px">
    <div class="section-title">Agent Performance</div>
    <table class="data-table">
      <thead><tr>
        <th>#</th><th>Agent</th><th>Ext</th><th>Total</th>
        <th>Answered</th><th>Missed</th><th>Inbound</th><th>Outbound</th><th>Local</th>
        <th>Talk Time</th><th>Avg Duration</th><th>Answer Rate</th>
      </tr></thead>
      <tbody id="agentBody"><tr><td colspan="12" class="loading-overlay">Loading...</td></tr></tbody>
    </table>
  </div>

  <!-- Queue SLA -->
  <div class="queue-table-wrap">
    <div class="section-title">Queue / IVR Statistics</div>
    <table class="data-table">
      <thead><tr><th>Number</th><th>Total</th><th>Answered</th><th>Answer Rate</th><th>Avg Duration</th></tr></thead>
      <tbody id="queueBody"><tr><td colspan="5" class="loading-overlay">Loading...</td></tr></tbody>
    </table>
  </div>

  <!-- Extra report types, without replacing the original dashboard layout -->
  <div class="queue-table-wrap">
    <div class="section-title">Detailed Call Reports</div>
    <div class="detail-tabs">
      <button class="detail-tab active" data-type="all" onclick="loadDetails('all')">All</button>
      <button class="detail-tab" data-type="answered" onclick="loadDetails('answered')">Answered</button>
      <button class="detail-tab" data-type="missed" onclick="loadDetails('missed')">Missed</button>
      <button class="detail-tab" data-type="inbound" onclick="loadDetails('inbound')">Inbound</button>
      <button class="detail-tab" data-type="outbound" onclick="loadDetails('outbound')">Outbound</button>
      <button class="detail-tab" data-type="local" onclick="loadDetails('local')">Local</button>
    </div>
    <div class="detail-table-wrap">
      <table class="data-table">
        <thead><tr><th>Time</th><th>From</th><th>To</th><th>Direction</th><th>Result</th><th>Talk</th><th>Hangup Cause</th></tr></thead>
        <tbody id="detailBody"><tr><td colspan="7" class="loading-overlay">Loading...</td></tr></tbody>
      </table>
    </div>
  </div>

</div><!-- /page -->
<div class="sk-footer">Sky Connect &copy; <?php echo date('Y'); ?> | Powered by SkyKin Technology</div>

<script>
const DOMAIN = '<?php echo $domain; ?>';
let charts = {};
let activePreset = 'today';
let activeDetail = 'all';

function setRange(days, preset) {
    activePreset = preset;
    document.querySelectorAll('.preset-btn').forEach(b => b.classList.remove('active'));
    event.target.classList.add('active');
    const to = new Date();
    const from = new Date(); from.setDate(from.getDate() - days);
    document.getElementById('fTo').value   = to.toISOString().slice(0,10);
    document.getElementById('fFrom').value = from.toISOString().slice(0,10);
    loadAll();
}

function api(endpoint, extra) {
    const from = document.getElementById('fFrom').value;
    const to   = document.getElementById('fTo').value;
    return fetch(`reports.php?api=${endpoint}&domain=${DOMAIN}&from=${from}&to=${to}${extra||''}`).then(r=>r.json());
}

function fmtDur(secs) {
    if (!secs) return '0s';
    const h = Math.floor(secs/3600), m = Math.floor((secs%3600)/60), s = secs%60;
    if (h) return h+'h '+m+'m';
    if (m) return m+'m '+s+'s';
    return s+'s';
}

function mkChart(id, type, data, opts) {
    if (charts[id]) charts[id].destroy();
    const ctx = document.getElementById(id);
    if (!ctx) return;
    charts[id] = new Chart(ctx, { type, data, options: { responsive:true, maintainAspectRatio:false,
        plugins:{ legend:{ labels:{ color:'#555', font:{size:11} } } },
        scales: type==='bar'||type==='line' ? {
            x:{ ticks:{color:'#888',font:{size:10}}, grid:{display:false} },
            y:{ ticks:{color:'#888',font:{size:10}}, grid:{display:false} }
        } : {}, ...opts } });
}

async function loadSummary() {
    const r = await api('summary');
    if (r.error) return;
    document.getElementById('kpiTotal').textContent    = r.total    || 0;
    document.getElementById('kpiAnswered').textContent = r.answered || 0;
    document.getElementById('kpiMissed').textContent   = r.missed   || 0;
    document.getElementById('kpiInbound').textContent  = r.inbound  || 0;
    document.getElementById('kpiOutbound').textContent = r.outbound || 0;
    document.getElementById('kpiLocal').textContent    = r.local    || 0;
    document.getElementById('kpiAvgDur').textContent   = r.avg_dur  || 0;
    const talkHrs = Math.round((r.total_talk||0)/3600 * 10)/10;
    document.getElementById('kpiTalkHrs').textContent  = talkHrs + 'h';
    const t = parseInt(r.total)||0, a = parseInt(r.answered)||0;
    document.getElementById('kpiAnswerRate').textContent = t ? Math.round(a/t*100)+'% answer rate' : '—';
    document.getElementById('kpiAbandon').textContent    = r.abandon_rate ? r.abandon_rate+'% abandon' : '0% abandon';
}

async function loadVolume() {
    const rows = await api('daily_volume');
    if (!Array.isArray(rows)) return;
    const labels    = rows.map(r => r.day.slice(5)); // MM-DD
    const answered  = rows.map(r => parseInt(r.answered)||0);
    const missed    = rows.map(r => parseInt(r.missed)||0);
    const avgDur    = rows.map(r => parseInt(r.avg_dur)||0);

    mkChart('chartVolume','bar',{
        labels,
        datasets:[
            { label:'Answered', data:answered, backgroundColor:'#238636bb', borderColor:'#3fb950', borderWidth:1 },
            { label:'Missed',   data:missed,   backgroundColor:'#da363388', borderColor:'#f85149', borderWidth:1 },
        ]
    });

    // Direction pie
    const inArr  = rows.map(r => parseInt(r.inbound)||0);
    const outArr = rows.map(r => parseInt(r.outbound)||0);
    const localArr = rows.map(r => parseInt(r.local)||0);
    const inSum  = inArr.reduce((a,b)=>a+b,0);
    const outSum = outArr.reduce((a,b)=>a+b,0);
    const localSum = localArr.reduce((a,b)=>a+b,0);
    mkChart('chartDirection','doughnut',{
        labels:['Inbound','Outbound','Local'],
        datasets:[{ data:[inSum,outSum,localSum], backgroundColor:['#388bfd','#f0883e','#3fb950'], borderWidth:0 }]
    });

    // Answer vs Missed pie
    const totA = answered.reduce((a,b)=>a+b,0);
    const totM = missed.reduce((a,b)=>a+b,0);
    mkChart('chartAM','doughnut',{
        labels:['Answered','Missed'],
        datasets:[{ data:[totA,totM], backgroundColor:['#3fb950','#f85149'], borderWidth:0 }]
    });
}

async function loadAgents() {
    const rows = await api('agent_performance');
    const tbody = document.getElementById('agentBody');
    if (!Array.isArray(rows) || rows.length === 0) {
        tbody.innerHTML = '<tr><td colspan="12" style="text-align:center;padding:30px;color:#888">No call data found</td></tr>';
        return;
    }
    const maxTotal = rows[0].total || 1;
    const rankColors = ['gold','silver','bronze'];
    tbody.innerHTML = rows.map((r,i) => {
        const rc = rankColors[i] || '';
        const ar = r.answer_rate;
        const arClass = ar>=80?'good':ar>=50?'ok':'bad';
        return `<tr>
          <td><div class="rank-badge ${rc}">${i+1}</div></td>
          <td><strong>${r.name}</strong></td>
          <td style="color:#888">${r.ext}</td>
          <td>
            <div class="bar-cell">
              <div class="bar-bg"><div class="bar-fill" style="width:${Math.round(r.total/maxTotal*100)}%"></div></div>
              <span>${r.total}</span>
            </div>
          </td>
          <td style="color:#3fb950">${r.answered}</td>
          <td style="color:#f85149">${r.missed}</td>
          <td>${r.inbound}</td>
          <td>${r.outbound}</td>
          <td>${r.local||0}</td>
          <td>${fmtDur(r.total_talk)}</td>
          <td>${r.avg_dur}s</td>
          <td><span class="answer-rate ${arClass}">${ar}%</span></td>
        </tr>`;
    }).join('');
}

async function loadQueues() {
    const rows = await api('queue_sla');
    const tbody = document.getElementById('queueBody');
    if (!Array.isArray(rows) || rows.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:20px;color:#888">No queue data found</td></tr>';
        return;
    }
    tbody.innerHTML = rows.map(r => {
        const total    = parseInt(r.total)||0;
        const answered = parseInt(r.answered)||0;
        const rate     = total ? Math.round(answered/total*100) : 0;
        return `<tr>
          <td><strong>${r.queue_num}</strong></td>
          <td>${total}</td>
          <td>${answered}</td>
          <td><span class="answer-rate ${rate>=80?'good':rate>=50?'ok':'bad'}">${rate}%</span></td>
          <td>${r.avg_dur||0}s</td>
        </tr>`;
    }).join('');
}

async function loadHourly() {
    const rows = await api('hourly_volume');
    const map = {};
    if (Array.isArray(rows)) rows.forEach(r => { map[parseInt(r.hour)] = r; });
    const hours = Array.from({length:24}, (_,i) => i);
    mkChart('chartHourly','bar',{
        labels:hours.map(h => String(h).padStart(2,'0')+':00'),
        datasets:[
          {label:'Answered',data:hours.map(h=>parseInt((map[h]||{}).answered)||0),backgroundColor:'#3fb950aa'},
          {label:'Missed',data:hours.map(h=>parseInt((map[h]||{}).missed)||0),backgroundColor:'#f85149aa'}
        ]
    });
}

async function loadDetails(type) {
    activeDetail = type || activeDetail;
    document.querySelectorAll('.detail-tab').forEach(b =>
        b.classList.toggle('active', b.dataset.type === activeDetail));
    const rows = await api('call_list','&type='+encodeURIComponent(activeDetail));
    const body = document.getElementById('detailBody');
    if (!Array.isArray(rows) || !rows.length) {
        body.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:30px;color:#888">No '
            + activeDetail + ' calls found</td></tr>';
        return;
    }
    body.innerHTML = rows.map(r => `<tr>
      <td>${r.call_time||''}</td>
      <td>${r.caller_id_number||''}</td>
      <td>${r.destination_number||''}</td>
      <td>${r.direction||''}</td>
      <td><span class="result-badge ${r.result}">${r.result}</span></td>
      <td>${fmtDur(r.billsec)}</td>
      <td style="color:#888">${r.hangup_cause||''}</td>
    </tr>`).join('');
}

function exportExcel() {
    const from = document.getElementById('fFrom').value;
    const to   = document.getElementById('fTo').value;
    window.location = `reports.php?api=export_excel&domain=${DOMAIN}&from=${from}&to=${to}&type=${activeDetail}`;
}

async function loadAll() {
    const status = document.getElementById('loadStatus');
    status.textContent = 'Loading...';
    await Promise.all([loadSummary(), loadVolume(), loadHourly(), loadAgents(), loadQueues(), loadDetails(activeDetail)]);
    status.textContent = 'Updated ' + new Date().toLocaleTimeString();
}

// Initial load
loadAll();
</script>
<script>
<?php echo skykin_js_bootstrap(); ?>
</script>
<script src="idle_watch.js?v=20260818"></script>
</body>
</html>
