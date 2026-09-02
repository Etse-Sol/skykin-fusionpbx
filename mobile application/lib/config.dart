/// ─────────────────────────────────────────────────────────────────────────────
/// AppConfig — single source of truth for the API base URL.
///
/// DESKTOP / LOCAL TESTING  →  use 'localhost'
/// PHONE ON SAME WIFI LAN   →  use your laptop's LAN IP, e.g. '192.168.1.10'
/// PRODUCTION               →  use your server hostname, e.g. 'api.skykin.com'
///
/// Change ONE value here and every screen picks it up automatically.
/// ─────────────────────────────────────────────────────────────────────────────
class AppConfig {
  AppConfig._(); // prevent instantiation

  /// The host (IP or hostname) of the PHP backend.
  /// Do NOT include 'http://' or a port here — those are added below.
  static const String serverHost = '192.168.1.10';

  /// The port the PHP built-in server (or production server) listens on.
  static const int serverPort = 80;

  /// Fully-assembled base URL used for all API calls.
  static String get baseUrl => 'http://$serverHost:$serverPort';
}
