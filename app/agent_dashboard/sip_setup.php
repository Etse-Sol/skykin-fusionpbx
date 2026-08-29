<?php
/**
 * One-shot SIP localStorage bootstrap then redirect to agent dashboard.
 */
require_once __DIR__ . '/session_bootstrap.php';
require_once __DIR__ . '/skykin_config.php';

$cfg    = skykin_config();
$agent  = (string)($_GET['agent'] ?? ($_SESSION['username'] ?? 'agent'));
$domain = skykin_domain_param($_GET['domain'] ?? null);
$host   = $cfg['sip_server'];
?>
<!DOCTYPE html>
<html><head><?php echo skykin_favicon_tag(); ?><meta charset="utf-8"><title>SIP setup</title></head>
<body>
<script>
localStorage.setItem('sip_server', <?php echo json_encode($host); ?>);
localStorage.setItem('sip_domain', <?php echo json_encode($domain); ?>);
<?php if (skykin_is_https()): ?>
localStorage.removeItem('sip_port');
<?php else: ?>
localStorage.setItem('sip_port', '5066');
<?php endif; ?>
window.location = <?php echo json_encode(
	'/app/agent_dashboard/index.php?agent=' . rawurlencode($agent) . '&domain=' . rawurlencode($domain)
); ?>;
</script>
</body></html>
