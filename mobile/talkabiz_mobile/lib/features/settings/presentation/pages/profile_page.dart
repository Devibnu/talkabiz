import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../auth/presentation/providers/auth_provider.dart';

class ProfilePage extends ConsumerStatefulWidget {
  const ProfilePage({super.key});

  @override
  ConsumerState<ProfilePage> createState() => _ProfilePageState();
}

class _ProfilePageState extends ConsumerState<ProfilePage> {
  late TextEditingController _nameController;
  late TextEditingController _phoneController;

  @override
  void initState() {
    super.initState();
    final user = ref.read(authControllerProvider).session?.user;
    _nameController = TextEditingController(text: user?.name ?? '');
    _phoneController = TextEditingController(text: user?.phone ?? '');
  }

  @override
  void dispose() {
    _nameController.dispose();
    _phoneController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final authState = ref.watch(authControllerProvider);
    final user = authState.session?.user;
    final theme = Theme.of(context);

    return Scaffold(
      appBar: AppBar(title: const Text('Profil')),
      body: ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.fromLTRB(20, 16, 20, 40),
        children: [
          // Avatar
          Center(
            child: CircleAvatar(
              radius: 44,
              backgroundColor: const Color(0xFF25D366),
              child: Text(
                _initials(user?.name ?? 'U'),
                style: theme.textTheme.headlineSmall?.copyWith(
                  color: Colors.white,
                  fontSize: 28,
                ),
              ),
            ),
          ),
          const SizedBox(height: 8),
          Center(
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
              decoration: BoxDecoration(
                color: const Color(0xFFECFDF5),
                borderRadius: BorderRadius.circular(8),
              ),
              child: Text(
                (user?.role ?? 'user').toUpperCase(),
                style: theme.textTheme.bodySmall?.copyWith(
                  color: const Color(0xFF25D366),
                  fontWeight: FontWeight.w700,
                  fontSize: 11,
                ),
              ),
            ),
          ),

          const SizedBox(height: 28),

          // -- Form Fields --
          _FieldLabel(label: 'Nama Lengkap'),
          const SizedBox(height: 6),
          TextFormField(
            controller: _nameController,
            decoration: _inputDecoration('Masukkan nama lengkap'),
          ),

          const SizedBox(height: 20),
          _FieldLabel(label: 'Email'),
          const SizedBox(height: 6),
          TextFormField(
            initialValue: user?.email ?? '',
            enabled: false,
            decoration: _inputDecoration('Email').copyWith(
              fillColor: const Color(0xFFF0F0F0),
            ),
          ),

          const SizedBox(height: 20),
          _FieldLabel(label: 'Nomor Telepon'),
          const SizedBox(height: 6),
          TextFormField(
            controller: _phoneController,
            keyboardType: TextInputType.phone,
            decoration: _inputDecoration('+62 xxx-xxxx-xxxx'),
          ),

          if (user?.businessName != null) ...[
            const SizedBox(height: 20),
            _FieldLabel(label: 'Nama Bisnis'),
            const SizedBox(height: 6),
            TextFormField(
              initialValue: user!.businessName,
              enabled: false,
              decoration: _inputDecoration('').copyWith(
                fillColor: const Color(0xFFF0F0F0),
              ),
            ),
          ],

          const SizedBox(height: 32),
          SizedBox(
            width: double.infinity,
            child: ElevatedButton(
              onPressed: () {
                // TODO: call update profile API when backend endpoint exists
                ScaffoldMessenger.of(context).showSnackBar(
                  const SnackBar(content: Text('Profil disimpan.')),
                );
                Navigator.pop(context);
              },
              child: const Text('Simpan'),
            ),
          ),
        ],
      ),
    );
  }

  String _initials(String name) {
    final parts = name.trim().split(RegExp(r'\s+'));
    if (parts.length >= 2) {
      return '${parts[0][0]}${parts[1][0]}'.toUpperCase();
    }
    return name.isNotEmpty ? name[0].toUpperCase() : 'U';
  }

  InputDecoration _inputDecoration(String hint) {
    return InputDecoration(
      hintText: hint,
      filled: true,
      fillColor: Colors.white,
      border: OutlineInputBorder(
        borderRadius: BorderRadius.circular(14),
        borderSide: const BorderSide(color: Color(0xFFE5E5E5)),
      ),
      enabledBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(14),
        borderSide: const BorderSide(color: Color(0xFFE5E5E5)),
      ),
      contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
    );
  }
}

class _FieldLabel extends StatelessWidget {
  const _FieldLabel({required this.label});
  final String label;

  @override
  Widget build(BuildContext context) {
    return Text(
      label,
      style: Theme.of(context).textTheme.titleMedium?.copyWith(fontSize: 13),
    );
  }
}
