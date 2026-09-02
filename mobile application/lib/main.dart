import 'dart:io';
import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'screens/login_screen.dart';
import 'screens/dashboard_screen.dart';
import 'utils/theme.dart';
import 'config.dart';

class MyHttpOverrides extends HttpOverrides {
  @override
  HttpClient createHttpClient(SecurityContext? context) {
    return super.createHttpClient(context)
      ..badCertificateCallback = (X509Certificate cert, String host, int port) => true;
  }
}

void main() async {
  WidgetsFlutterBinding.ensureInitialized();
  HttpOverrides.global = MyHttpOverrides();
  runApp(const SkyKinApp());
}

class SkyKinApp extends StatelessWidget {
  const SkyKinApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'SkyKin',
      debugShowCheckedModeBanner: false,
      theme: SkyKinTheme.theme,
      home: const _Splash(),
    );
  }
}

/// Splash — checks for saved credentials and routes accordingly
class _Splash extends StatefulWidget {
  const _Splash();
  @override
  State<_Splash> createState() => _SplashState();
}

class _SplashState extends State<_Splash> {
  @override
  void initState() {
    super.initState();
    _init();
  }

  Future<void> _init() async {
    await Future.delayed(const Duration(milliseconds: 800));
    final prefs = await SharedPreferences.getInstance();
    final ext      = prefs.getString('last_extension') ?? '';
    final sipPass  = prefs.getString('sip_password') ?? '';
    final domain   = prefs.getString('domain') ?? '';
    final serverIp = prefs.getString('server_ip') ?? AppConfig.serverHost;
    final username = prefs.getString('username') ?? '';

    if (!mounted) return;
    if (ext.isNotEmpty && sipPass.isNotEmpty) {
      Navigator.of(context).pushReplacement(MaterialPageRoute(
        builder: (_) => DashboardScreen(
          extension:   ext,
          sipPassword: sipPass,
          domain:      domain,
          serverIp:    serverIp,
          username:    username,
        ),
      ));
    } else {
      Navigator.of(context).pushReplacement(
        MaterialPageRoute(builder: (_) => const LoginScreen()),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Container(
        decoration: const BoxDecoration(gradient: SkyKinTheme.headerGradient),
        child: const Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(Icons.phone_in_talk_rounded, color: Colors.white, size: 72),
              SizedBox(height: 16),
              Text('SKYKIN', style: TextStyle(color: Colors.white, fontSize: 32, fontWeight: FontWeight.w900, letterSpacing: 4)),
              SizedBox(height: 8),
              Text('Agent Softphone', style: TextStyle(color: Colors.white70, fontSize: 14)),
              SizedBox(height: 40),
              CircularProgressIndicator(color: Colors.white, strokeWidth: 2),
            ],
          ),
        ),
      ),
    );
  }
}
