import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

abstract final class AppTheme {
  static const _primary = Color(0xFF25D366);
  static const _secondary = Color(0xFF1959D1);
  static const _surface = Color(0xFFF4F1EA);
  static const _surfaceAlt = Color(0xFFFFFCF6);
  static const _text = Color(0xFF1C2A1F);
  static const _muted = Color(0xFF6A756C);

  static ThemeData light() {
    final base = ThemeData.light(useMaterial3: true);
    final textTheme =
        GoogleFonts.plusJakartaSansTextTheme(base.textTheme).copyWith(
      headlineMedium: GoogleFonts.plusJakartaSans(
        fontSize: 32,
        fontWeight: FontWeight.w700,
        color: _text,
        letterSpacing: -0.6,
      ),
      headlineSmall: GoogleFonts.plusJakartaSans(
        fontSize: 27,
        fontWeight: FontWeight.w700,
        color: _text,
        letterSpacing: -0.4,
      ),
      titleLarge: GoogleFonts.plusJakartaSans(
        fontSize: 22,
        fontWeight: FontWeight.w700,
        color: _text,
      ),
      titleMedium: GoogleFonts.plusJakartaSans(
        fontSize: 16,
        fontWeight: FontWeight.w700,
        color: _text,
      ),
      bodyLarge: GoogleFonts.plusJakartaSans(
        fontSize: 16,
        color: _text,
        height: 1.45,
      ),
      bodyMedium: GoogleFonts.plusJakartaSans(
        fontSize: 14,
        color: _muted,
        height: 1.45,
      ),
      bodySmall: GoogleFonts.plusJakartaSans(
        fontSize: 12,
        color: _muted,
        height: 1.4,
      ),
    );

    return base.copyWith(
      scaffoldBackgroundColor: _surface,
      textTheme: textTheme,
      colorScheme: base.colorScheme.copyWith(
        primary: _primary,
        secondary: _secondary,
        surface: _surfaceAlt,
        onSurface: _text,
      ),
      appBarTheme: const AppBarTheme(
        backgroundColor: Colors.transparent,
        foregroundColor: _text,
        elevation: 0,
        centerTitle: false,
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: Colors.white,
        contentPadding: const EdgeInsets.symmetric(
          horizontal: 16,
          vertical: 14,
        ),
        hintStyle: const TextStyle(color: _muted),
        labelStyle: const TextStyle(color: _muted),
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(18),
          borderSide: BorderSide.none,
        ),
        enabledBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(18),
          borderSide: const BorderSide(color: Color(0xFFE6E0D5)),
        ),
        focusedBorder: OutlineInputBorder(
          borderRadius: BorderRadius.circular(18),
          borderSide: const BorderSide(color: _primary, width: 1.2),
        ),
      ),
      elevatedButtonTheme: ElevatedButtonThemeData(
        style: ElevatedButton.styleFrom(
          minimumSize: const Size.fromHeight(52),
          backgroundColor: _primary,
          foregroundColor: Colors.white,
          elevation: 0,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(18),
          ),
        ),
      ),
      cardTheme: CardThemeData(
        color: Colors.white,
        elevation: 0,
        margin: EdgeInsets.zero,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(28)),
      ),
      dividerColor: const Color(0xFFE7E1D6),
      snackBarTheme: SnackBarThemeData(
        behavior: SnackBarBehavior.floating,
        backgroundColor: _text,
        contentTextStyle: GoogleFonts.plusJakartaSans(
          color: Colors.white,
          fontWeight: FontWeight.w600,
        ),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
      ),
      navigationBarTheme: NavigationBarThemeData(
        labelTextStyle: WidgetStatePropertyAll(
          GoogleFonts.plusJakartaSans(
              fontSize: 12, fontWeight: FontWeight.w700),
        ),
        backgroundColor: Colors.white.withValues(alpha: 0.92),
        elevation: 0,
        indicatorColor: const Color(0x1F25D366),
      ),
    );
  }
}
