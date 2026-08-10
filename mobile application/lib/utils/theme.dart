import 'package:flutter/material.dart';

class SkyKinTheme {
  static const Color primaryBlue  = Color(0xFF0047AB);
  static const Color accentCyan   = Color(0xFF00B4D8);
  static const Color background   = Color(0xFFF0F2F5);
  static const Color surface      = Color(0xFFFFFFFF);
  static const Color textPrimary  = Color(0xFF333333);
  static const Color textMuted    = Color(0xFF888888);
  static const Color success      = Color(0xFF28A745);
  static const Color danger       = Color(0xFFDC3545);
  static const Color warning      = Color(0xFFFD7E14);

  static const LinearGradient headerGradient = LinearGradient(
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
    colors: [Color(0xFF0047AB), Color(0xFF00B4D8)],
  );

  static ThemeData get theme => ThemeData(
    useMaterial3: true,
    colorScheme: ColorScheme.fromSeed(seedColor: primaryBlue),
    scaffoldBackgroundColor: background,
    fontFamily: 'sans-serif',
    appBarTheme: const AppBarTheme(
      backgroundColor: Colors.transparent,
      elevation: 0,
      foregroundColor: Colors.white,
    ),
    textTheme: const TextTheme(
      bodyMedium: TextStyle(color: textPrimary, fontSize: 14),
      titleMedium: TextStyle(color: textPrimary, fontWeight: FontWeight.w600),
    ),
    cardTheme: CardThemeData(
      color: surface,
      elevation: 2,
      shadowColor: Colors.black12,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
    ),
  );
}
