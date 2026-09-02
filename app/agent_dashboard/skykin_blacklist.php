<?php
// Safe when included by the dashboard: never run, never fatal.
if (!isset($_GET['action']) || !in_array($_GET['action'], ['blacklist_list', 'blacklist_add', 'blacklist_del'], true)) {
	return;
}
return;
