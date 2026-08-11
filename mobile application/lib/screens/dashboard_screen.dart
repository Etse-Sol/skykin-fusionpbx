import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:sip_ua/sip_ua.dart';
import '../utils/theme.dart';
import 'call_screen.dart';
import 'tabs/dialer_tab.dart';
import 'tabs/voicemail_tab.dart';
import 'tabs/sms_tab.dart';
import 'tabs/settings_tab.dart';
import 'login_screen.dart';
import 'package:shared_preferences/shared_preferences.dart';

class DashboardScreen extends StatefulWidget {
  final String extension;
  final String sipPassword;
  final String domain;
  final String serverIp;
  final String username;

  const DashboardScreen({
    super.key,
    required this.extension,
    required this.sipPassword,
    required this.domain,
    required this.serverIp,
    required this.username,
  });

  @override
  State<DashboardScreen> createState() => _DashboardScreenState();
}

class _DashboardScreenState extends State<DashboardScreen> implements SipUaHelperListener {
  final SIPUAHelper _helper = SIPUAHelper();
  RegistrationState _regState = RegistrationState(state: RegistrationStateEnum.NONE);
  TransportState? _transportState;
  int _tab = 0;

  // ── On-screen SIP event log (debug) ───────────────────────────────────────
  final List<_SipLogEntry> _sipLog = [];
  bool _showSipLog = false;

  void _log(String icon, String message) {
    final entry = _SipLogEntry(
      icon: icon,
      message: message,
      time: TimeOfDay.now(),
    );
    debugPrint('[SkyKin SIP] $message');
    if (mounted) setState(() => _sipLog.insert(0, entry));
  }

  @override
  void initState() {
    super.initState();
    _helper.addSipUaHelperListener(this);
    _registerSip();
  }

  void _registerSip() {
    final settings = UaSettings();
    // ws:// port 5066 — plain WebSocket binding enabled in FusionPBX internal
    // profile (ws-binding = :5066, enabled=true).
    // TCP connectivity to 192.168.1.10:5066 confirmed reachable (TcpTestSucceeded=True)
    // and HTTP 101 WebSocket upgrade confirmed working from this machine.
    settings.webSocketUrl = 'ws://${widget.domain}:5066';
    settings.webSocketSettings.allowBadCertificate = true;
    settings.transportType = TransportType.WS;
    settings.uri = 'sip:${widget.extension}@${widget.domain}';
    settings.authorizationUser = widget.extension;
    settings.password = widget.sipPassword;
    settings.displayName = widget.username.isNotEmpty ? widget.username : widget.extension;
    settings.userAgent = 'SkyKin/1.0';
    settings.dtmfMode = DtmfMode.INFO;

    _log('🔌', 'Connecting → ws://${widget.domain}:5066');
    _log('📋', 'URI: sip:${widget.extension}@${widget.domain}  auth: ${widget.extension}  pass_len: ${widget.sipPassword.length}');
    _helper.start(settings);
  }

  @override
  void dispose() {
    _helper.removeSipUaHelperListener(this);
    _helper.stop();
    super.dispose();
  }

  @override
  void registrationStateChanged(RegistrationState state) {
    final cause = state.cause?.toString() ?? 'none';
    final stateEnum = state.state ?? RegistrationStateEnum.NONE;
    _log(_stateIcon(stateEnum), 'REG: $stateEnum  cause: $cause');
    setState(() => _regState = state);
  }

  String _stateIcon(RegistrationStateEnum s) {
    switch (s) {
      case RegistrationStateEnum.REGISTERED: return '✅';
      case RegistrationStateEnum.REGISTRATION_FAILED: return '❌';
      case RegistrationStateEnum.UNREGISTERED: return '🔴';
      default: return '🔄';
    }
  }

  @override
  void callStateChanged(Call call, CallState state) {
    _log('📞', 'CALL: ${state.state}  id: ${call.id}');
    if (state.state == CallStateEnum.CALL_INITIATION ||
        state.state == CallStateEnum.ACCEPTED) {
      Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => CallScreen(call: call, helper: _helper),
      ));
    }
  }

  @override
  void transportStateChanged(TransportState state) {
    _log('🌐', 'TRANSPORT: ${state.state}');
    setState(() => _transportState = state);
  }

  @override void onNewMessage(SIPMessageRequest msg) {}
  @override void onNewNotify(Notify ntf) {}
  @override void onNewReinvite(ReInvite event) {}

  Color get _regColor {
    switch (_regState.state ?? RegistrationStateEnum.NONE) {
      case RegistrationStateEnum.REGISTERED: return SkyKinTheme.success;
      case RegistrationStateEnum.UNREGISTERED:
      case RegistrationStateEnum.REGISTRATION_FAILED: return SkyKinTheme.danger;
      default: return SkyKinTheme.warning;
    }
  }

  String get _regLabel {
    switch (_regState.state ?? RegistrationStateEnum.NONE) {
      case RegistrationStateEnum.REGISTERED:
        return 'Registered';
      case RegistrationStateEnum.REGISTRATION_FAILED:
        final cause = _regState.cause?.toString() ?? '';
        return cause.isNotEmpty ? 'Failed: $cause' : 'Reg Failed';
      case RegistrationStateEnum.UNREGISTERED:
        return 'Unregistered';
      default:
        if (_transportState != null) {
          final ts = _transportState!.state.toString();
          if (ts.contains('CONNECTED')) return 'WS Connected\u2026';
          if (ts.contains('DISCONNECTED')) return 'WS Down';
        }
        return 'Connecting\u2026';
    }
  }

  Future<void> _signOut() async {
    _helper.stop();
    final prefs = await SharedPreferences.getInstance();
    await prefs.clear();
    if (!mounted) return;
    Navigator.of(context).pushReplacement(MaterialPageRoute(builder: (_) => const LoginScreen()));
  }

  @override
  Widget build(BuildContext context) {
    final tabs = [
      DialerTab(helper: _helper, domain: widget.domain, extension: widget.extension),
      VoicemailTab(extension: widget.extension, serverIp: widget.serverIp),
      SmsTab(serverIp: widget.serverIp),
      SettingsTab(extension: widget.extension, domain: widget.domain, serverIp: widget.serverIp, onSignOut: _signOut),
    ];

    return Scaffold(
      body: Column(
        children: [
          // ── Gradient header ─────────────────────────────────────────────
          Container(
            decoration: const BoxDecoration(gradient: SkyKinTheme.headerGradient),
            child: SafeArea(
              bottom: false,
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 12),
                child: Row(
                  children: [
                    const Icon(Icons.phone_in_talk_rounded, color: Colors.white, size: 26),
                    const SizedBox(width: 10),
                    RichText(text: const TextSpan(
                      style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800),
                      children: [
                        TextSpan(text: 'SKY', style: TextStyle(color: Colors.white)),
                        TextSpan(text: 'KIN', style: TextStyle(color: Color(0xFF00E5FF))),
                      ],
                    )),
                    const Spacer(),
                    // Registration status pill — tap to toggle SIP log
                    GestureDetector(
                      onTap: () => setState(() => _showSipLog = !_showSipLog),
                      child: AnimatedContainer(
                        duration: const Duration(milliseconds: 300),
                        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 5),
                        decoration: BoxDecoration(
                          color: Colors.white.withValues(alpha: 0.15),
                          borderRadius: BorderRadius.circular(20),
                          border: Border.all(color: Colors.white.withValues(alpha: 0.3)),
                        ),
                        child: Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            AnimatedContainer(
                              duration: const Duration(milliseconds: 300),
                              width: 8, height: 8,
                              decoration: BoxDecoration(color: _regColor, shape: BoxShape.circle),
                            ),
                            const SizedBox(width: 6),
                            Text(_regLabel,
                              style: const TextStyle(color: Colors.white, fontSize: 12, fontWeight: FontWeight.w600),
                              overflow: TextOverflow.ellipsis,
                            ),
                            const SizedBox(width: 4),
                            Icon(
                              _showSipLog ? Icons.expand_less : Icons.expand_more,
                              color: Colors.white70,
                              size: 14,
                            ),
                          ],
                        ),
                      ),
                    ),
                    const SizedBox(width: 8),
                    Text(widget.extension, style: const TextStyle(color: Colors.white70, fontSize: 13)),
                  ],
                ),
              ),
            ),
          ),

          // ── SIP Debug Log Panel (collapsible) ───────────────────────────
          if (_showSipLog) _buildSipLogPanel(),

          Expanded(child: tabs[_tab]),
        ],
      ),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _tab,
        onDestinationSelected: (i) => setState(() => _tab = i),
        backgroundColor: Colors.white,
        indicatorColor: SkyKinTheme.primaryBlue.withValues(alpha: 0.1),
        destinations: const [
          NavigationDestination(icon: Icon(Icons.dialpad_rounded), label: 'Dialer'),
          NavigationDestination(icon: Icon(Icons.voicemail_rounded), label: 'Voicemail'),
          NavigationDestination(icon: Icon(Icons.message_rounded), label: 'SMS'),
          NavigationDestination(icon: Icon(Icons.settings_rounded), label: 'Settings'),
        ],
      ),
    );
  }

  Widget _buildSipLogPanel() {
    return Container(
      constraints: const BoxConstraints(maxHeight: 220),
      color: const Color(0xFF0D1117),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: [
          // Panel header
          Container(
            color: const Color(0xFF161B22),
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
            child: Row(
              children: [
                const Icon(Icons.terminal_rounded, color: Color(0xFF58A6FF), size: 14),
                const SizedBox(width: 6),
                const Text('SIP Debug Log', style: TextStyle(color: Color(0xFF58A6FF), fontSize: 11, fontWeight: FontWeight.w700, fontFamily: 'monospace')),
                const Spacer(),
                if (_sipLog.isNotEmpty)
                  GestureDetector(
                    onTap: () {
                      final text = _sipLog.reversed.map((e) => '[${e.time.format(context)}] ${e.icon} ${e.message}').join('\n');
                      Clipboard.setData(ClipboardData(text: text));
                      ScaffoldMessenger.of(context).showSnackBar(
                        const SnackBar(content: Text('SIP log copied to clipboard'), duration: Duration(seconds: 2)),
                      );
                    },
                    child: const Row(children: [
                      Icon(Icons.copy_rounded, color: Color(0xFF8B949E), size: 12),
                      SizedBox(width: 4),
                      Text('Copy', style: TextStyle(color: Color(0xFF8B949E), fontSize: 10)),
                    ]),
                  ),
              ],
            ),
          ),
          // Log entries
          Flexible(
            child: _sipLog.isEmpty
                ? const Center(
                    child: Padding(
                      padding: EdgeInsets.all(16),
                      child: Text('No SIP events yet…', style: TextStyle(color: Color(0xFF8B949E), fontSize: 12, fontFamily: 'monospace')),
                    ),
                  )
                : ListView.builder(
                    reverse: false,
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                    itemCount: _sipLog.length,
                    itemBuilder: (ctx, i) {
                      final e = _sipLog[i];
                      final isError = e.message.contains('FAILED') || e.message.contains('❌') || e.message.contains('Down');
                      return Padding(
                        padding: const EdgeInsets.symmetric(vertical: 1.5),
                        child: Text(
                          '[${e.time.format(ctx)}] ${e.icon} ${e.message}',
                          style: TextStyle(
                            color: isError ? const Color(0xFFFF7B72) : const Color(0xFF7EE787),
                            fontSize: 10.5,
                            fontFamily: 'monospace',
                            height: 1.5,
                          ),
                        ),
                      );
                    },
                  ),
          ),
        ],
      ),
    );
  }
}

/// Single SIP log entry
class _SipLogEntry {
  final String icon;
  final String message;
  final TimeOfDay time;
  const _SipLogEntry({required this.icon, required this.message, required this.time});
}
