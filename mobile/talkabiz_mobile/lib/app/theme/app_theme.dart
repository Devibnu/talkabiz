import 'package:flutter/material.dart';

abstract final class AppTheme {
  static const _primary = Color(0xFF25D366);
  static const _surface = Color(0xFFF7F8FC);
  static const _text = Color(0xFF22304A);

  static ThemeData light() {
    final base = ThemeData.light(useMaterial3: true);

    return base.copyWith(
      scaffoldBackgroundColor: _surface,
      colorScheme: base.colorScheme.copyWith(
        primary: _primary,
        surface: Colors.white,
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
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
        border: OutlineInputBorder(
          borderRadius: BorderRadius.circular(16),
          borderSide: BorderSide.none,
        ),
      ),
      elevatedButtonTheme: ElevatedButtonThemeData(
        style: ElevatedButton.styleFrom(
          minimumSize: const Size.fromHeight(52),
          backgroundColor: _primary,
          foregroundColor: Colors.white,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(16),
          ),
        ),
      ),
      cardTheme: CardTheme(
        color: Colors.white,
        elevation: 0,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(24),
        ),
      ),
    );
  }
}
