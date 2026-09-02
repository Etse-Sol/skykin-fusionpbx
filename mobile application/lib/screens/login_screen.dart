import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:http/http.dart' as http;
import '../utils/theme.dart';
import '../config.dart';
import 'dashboard_screen.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});
  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> with SingleTickerProviderStateMixin {
  final _extCtrl  = TextEditingController();
  final _passCtrl = TextEditingController();
  final _serverCtrl = TextEditingController(text: AppConfig.serverHost);
  bool _loading = false;
  bool _showPass = false;
  String? _error;
  late AnimationController _anim;
  late Animation<double> _fade;

  @override
  void initState() {
    super.initState();
    _anim = AnimationController(vsync: this, duration: const Duration(milliseconds: 700));
    _fade = CurvedAnimation(parent: _anim, curve: Curves.easeOut);
    _anim.forward();
    _loadSaved();
  }

  Future<void> _loadSaved() async {
    final prefs = await SharedPreferences.getInstance();
    _extCtrl.text    = prefs.getString('last_extension') ?? '';
    _serverCtrl.text = prefs.getString('server_ip') ?? AppConfig.serverHost;
  }

  @override
  void dispose() {
    _anim.dispose();
    _extCtrl.dispose();
    _passCtrl.dispose();
    _serverCtrl.dispose();
    super.dispose();
  }

  Future<void> _doLogin() async {
    if (_extCtrl.text.trim().isEmpty || _passCtrl.text.isEmpty) {
      setState(() => _error = 'Please enter your extension and password.');
      return;
    }
    setState(() { _loading = true; _error = null; });

    final serverIp = _serverCtrl.text.trim().isEmpty ? AppConfig.serverHost : _serverCtrl.text.trim();
    final url = Uri.parse('https://$serverIp/app/mobile_api/index.php?action=login');

    try {
      final resp = await http.post(
        url,
        headers: {'Content-Type': 'application/json'},
        body: jsonEncode({'extension': _extCtrl.text.trim(), 'password': _passCtrl.text}),
      ).timeout(const Duration(seconds: 10));

      final data = jsonDecode(resp.body) as Map<String, dynamic>;
      if (resp.statusCode == 200 && data['status'] == 'success') {
        final prefs = await SharedPreferences.getInstance();
        await prefs.setString('last_extension', data['extension'] ?? '');
        await prefs.setString('server_ip', serverIp);
        await prefs.setString('sip_password', data['sip_password'] ?? '');
        await prefs.setString('domain', data['domain'] ?? serverIp);
        await prefs.setString('username', data['username'] ?? '');
        if (!mounted) return;
        Navigator.of(context).pushReplacement(
          MaterialPageRoute(builder: (_) => DashboardScreen(
            extension:   data['extension'] ?? '',
            sipPassword: data['sip_password'] ?? '',
            domain:      data['domain'] ?? serverIp,
            serverIp:    serverIp,
            username:    data['username'] ?? '',
          )),
        );
      } else {
        setState(() => _error = data['error'] ?? 'Login failed.');
      }
    } catch (e) {
      setState(() => _error = 'Connection error: $e');
    } finally {
      setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Container(
        decoration: const BoxDecoration(gradient: SkyKinTheme.headerGradient),
        child: SafeArea(
          child: FadeTransition(
            opacity: _fade,
            child: Center(
              child: SingleChildScrollView(
                padding: const EdgeInsets.all(28),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    // Logo
                    const Icon(Icons.phone_in_talk_rounded, color: Colors.white, size: 64),
                    const SizedBox(height: 12),
                    RichText(text: const TextSpan(
                      style: TextStyle(fontSize: 32, fontWeight: FontWeight.w800, letterSpacing: 1.5),
                      children: [
                        TextSpan(text: 'SKY', style: TextStyle(color: Colors.white)),
                        TextSpan(text: 'KIN', style: TextStyle(color: Color(0xFF00E5FF))),
                      ],
                    )),
                    const SizedBox(height: 4),
                    const Text('Agent Softphone', style: TextStyle(color: Colors.white70, fontSize: 14, letterSpacing: 0.5)),
                    const SizedBox(height: 36),

                    // Card
                    Container(
                      decoration: BoxDecoration(
                        color: Colors.white,
                        borderRadius: BorderRadius.circular(20),
                        boxShadow: const [BoxShadow(color: Colors.black26, blurRadius: 24, offset: Offset(0, 8))],
                      ),
                      padding: const EdgeInsets.all(28),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          const Text('Sign In', style: TextStyle(fontSize: 20, fontWeight: FontWeight.w700, color: SkyKinTheme.primaryBlue)),
                          const SizedBox(height: 20),

                          _field(_extCtrl, 'Extension or Username', Icons.dialpad_rounded),
                          const SizedBox(height: 14),

                          _field(_passCtrl, 'Password', Icons.lock_outline_rounded,
                              obscure: !_showPass,
                              suffix: IconButton(
                                icon: Icon(_showPass ? Icons.visibility_off : Icons.visibility, size: 20, color: Colors.grey),
                                onPressed: () => setState(() => _showPass = !_showPass),
                              )),
                          const SizedBox(height: 14),

                          _field(_serverCtrl, 'Server IP', Icons.dns_outlined),
                          const SizedBox(height: 6),

                          if (_error != null) ...[
                            const SizedBox(height: 8),
                            Container(
                              padding: const EdgeInsets.all(10),
                              decoration: BoxDecoration(color: Colors.red.shade50, borderRadius: BorderRadius.circular(8), border: Border.all(color: Colors.red.shade200)),
                              child: Text(_error!, style: TextStyle(color: Colors.red.shade700, fontSize: 13)),
                            ),
                          ],
                          const SizedBox(height: 20),

                          SizedBox(
                            height: 50,
                            child: ElevatedButton(
                              onPressed: _loading ? null : _doLogin,
                              style: ElevatedButton.styleFrom(
                                backgroundColor: SkyKinTheme.primaryBlue,
                                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                              ),
                              child: _loading
                                  ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2))
                                  : const Text('Sign In', style: TextStyle(fontSize: 16, fontWeight: FontWeight.w700, color: Colors.white)),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _field(TextEditingController ctrl, String hint, IconData icon, {bool obscure = false, Widget? suffix}) {
    return TextField(
      controller: ctrl,
      obscureText: obscure,
      style: const TextStyle(fontSize: 15),
      decoration: InputDecoration(
        hintText: hint,
        hintStyle: const TextStyle(color: Colors.grey, fontSize: 14),
        prefixIcon: Icon(icon, color: SkyKinTheme.primaryBlue, size: 20),
        suffixIcon: suffix,
        filled: true,
        fillColor: const Color(0xFFF5F7FA),
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
        border: OutlineInputBorder(borderRadius: BorderRadius.circular(10), borderSide: BorderSide.none),
        focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(10), borderSide: const BorderSide(color: SkyKinTheme.primaryBlue, width: 1.5)),
      ),
    );
  }
}
