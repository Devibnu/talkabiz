import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../domain/entities/template_detail.dart';
import '../providers/template_provider.dart';

/// Quick template presets — same as web's quickTemplates
const _quickTemplates = <String, Map<String, String>>{
  // === UMUM ===
  'welcome': {
    'nama': 'Selamat Datang',
    'kategori': 'utility',
    'konten':
        'Halo {{nama}}, selamat datang! 👋\n\nTerima kasih telah bergabung bersama kami. Kami siap membantu kebutuhan Anda.\n\nJika ada pertanyaan, silakan hubungi kami kapan saja.',
  },
  'thank_you': {
    'nama': 'Ucapan Terima Kasih',
    'kategori': 'marketing',
    'konten':
        'Halo {{nama}}, terima kasih atas kepercayaan Anda! 🙏\n\nKami sangat menghargai Anda sebagai pelanggan kami. Semoga kami bisa terus memberikan layanan terbaik.\n\nSampai jumpa kembali!',
  },
  'promo': {
    'nama': 'Promo Diskon',
    'kategori': 'marketing',
    'konten':
        'Halo {{nama}}, ada promo spesial untuk Anda! 🔥\n\nDapatkan diskon hingga 50% untuk semua produk kami.\n\nPromo berlaku sampai akhir bulan ini. Jangan sampai kelewatan!\n\nInfo lebih lanjut hubungi kami.',
  },
  'payment_remind': {
    'nama': 'Pengingat Pembayaran',
    'kategori': 'utility',
    'konten':
        'Halo {{nama}}, ini pengingat pembayaran Anda. 💰\n\nMohon segera selesaikan pembayaran agar layanan bisa diproses.\n\nJika sudah bayar, abaikan pesan ini. Terima kasih!',
  },
  'event_invite': {
    'nama': 'Undangan Acara',
    'kategori': 'marketing',
    'konten':
        'Halo {{nama}}, Anda diundang ke acara spesial kami! 🎉\n\nJangan lewatkan! Kami tunggu kehadiran Anda.\n\nUntuk info lengkap, silakan hubungi kami.',
  },
  'feedback': {
    'nama': 'Minta Ulasan',
    'kategori': 'marketing',
    'konten':
        'Halo {{nama}}, terima kasih telah menggunakan layanan kami! ⭐\n\nKami ingin tahu pendapat Anda. Mohon luangkan waktu sebentar untuk memberikan ulasan.\n\nMasukan Anda sangat berarti bagi kami. Terima kasih! 🙏',
  },
  // === TOKO / RETAIL ===
  'order_confirm': {
    'nama': 'Konfirmasi Pesanan',
    'kategori': 'utility',
    'konten':
        'Halo {{nama}}, pesanan Anda sudah kami terima! 📦\n\nPesanan sedang diproses dan kami akan kabari jika sudah dikirim.\n\nTerima kasih telah berbelanja!',
  },
  'shipping': {
    'nama': 'Notifikasi Pengiriman',
    'kategori': 'utility',
    'konten':
        'Halo {{nama}}, pesanan Anda sudah dikirim! 🚚\n\nSilakan cek status pengiriman secara berkala.\n\nTerima kasih telah berbelanja!',
  },
  'restock': {
    'nama': 'Produk Tersedia Kembali',
    'kategori': 'marketing',
    'konten':
        'Halo {{nama}}, kabar baik! 🔔\n\nProduk yang Anda tunggu sudah tersedia kembali.\n\nSegera pesan sebelum kehabisan lagi. Stok terbatas!',
  },
  // === JASA / LAYANAN ===
  'booking_confirm': {
    'nama': 'Konfirmasi Booking',
    'kategori': 'utility',
    'konten':
        'Halo {{nama}}, booking Anda sudah dikonfirmasi! 📋\n\nMohon hadir tepat waktu sesuai jadwal.\n\nJika perlu reschedule, silakan hubungi kami. Terima kasih!',
  },
  'appointment_remind': {
    'nama': 'Pengingat Jadwal',
    'kategori': 'utility',
    'konten':
        'Halo {{nama}}, ini pengingat untuk jadwal Anda besok. ⏰\n\nJika perlu reschedule, silakan hubungi kami segera.\n\nTerima kasih!',
  },
  'service_done': {
    'nama': 'Layanan Selesai',
    'kategori': 'utility',
    'konten':
        'Halo {{nama}}, layanan Anda sudah selesai! ✅\n\nTerima kasih telah mempercayakan kepada kami.\n\nSemoga puas dengan hasilnya! 🙏',
  },
  // === SEKOLAH ===
  'school_info': {
    'nama': 'Info Sekolah',
    'kategori': 'utility',
    'konten':
        'Kepada Bapak/Ibu {{nama}}, 📢\n\nDengan ini kami sampaikan informasi penting dari sekolah.\n\nSilakan hubungi pihak sekolah untuk informasi lebih lanjut.\n\nTerima kasih atas perhatiannya.',
  },
  'school_payment': {
    'nama': 'Tagihan SPP',
    'kategori': 'utility',
    'konten':
        'Kepada Bapak/Ibu {{nama}}, 💳\n\nMohon segera melakukan pembayaran SPP/biaya sekolah sebelum jatuh tempo.\n\nJika sudah membayar, abaikan pesan ini.\n\nTerima kasih.',
  },
  'school_event': {
    'nama': 'Undangan Kegiatan Sekolah',
    'kategori': 'marketing',
    'konten':
        'Kepada Bapak/Ibu {{nama}}, 🏫\n\nDengan ini kami mengundang Bapak/Ibu untuk hadir pada kegiatan sekolah.\n\nKehadiran Bapak/Ibu sangat kami harapkan.\n\nTerima kasih.',
  },
  // === KANTOR ===
  'meeting_invite': {
    'nama': 'Undangan Rapat',
    'kategori': 'utility',
    'konten':
        'Halo {{nama}}, 📅\n\nAnda diundang untuk menghadiri rapat.\n\nMohon konfirmasi kehadiran Anda. Terima kasih.',
  },
  'company_announce': {
    'nama': 'Pengumuman Perusahaan',
    'kategori': 'utility',
    'konten':
        'Kepada {{nama}}, 📣\n\nBerikut informasi penting dari perusahaan.\n\nDemikian pengumuman ini disampaikan. Terima kasih atas perhatiannya.',
  },
  // === F&B ===
  'order_ready': {
    'nama': 'Pesanan Siap',
    'kategori': 'utility',
    'konten':
        'Halo {{nama}}, pesanan Anda sudah siap! 🍔\n\nSilakan diambil.\n\nSelamat menikmati! 😊',
  },
  'menu_promo': {
    'nama': 'Promo Menu Baru',
    'kategori': 'marketing',
    'konten':
        'Halo {{nama}}, ada menu baru yang wajib dicoba! 🍕\n\nDapatkan harga spesial untuk menu terbaru kami.\n\nYuk, pesan sekarang!',
  },
  // === KESEHATAN ===
  'appointment_health': {
    'nama': 'Pengingat Jadwal Kontrol',
    'kategori': 'utility',
    'konten':
        'Halo {{nama}}, ini pengingat jadwal kontrol kesehatan Anda. 🩺\n\nMohon hadir tepat waktu.\n\nJika perlu ubah jadwal, segera hubungi kami. Terima kasih!',
  },
  'health_promo': {
    'nama': 'Promo Layanan Kesehatan',
    'kategori': 'marketing',
    'konten':
        'Halo {{nama}}, jaga kesehatan Anda! 💊\n\nDapatkan promo spesial untuk layanan kesehatan kami.\n\nSegera daftarkan diri Anda. Kesehatan adalah investasi terbaik!',
  },
};

/// Grouped labels for quick template dropdown
const _quickTemplateGroups = <String, List<String>>{
  'Umum (Semua Usaha)': [
    'welcome', 'thank_you', 'promo', 'payment_remind', 'event_invite', 'feedback',
  ],
  'Toko / Retail / Online Shop': ['order_confirm', 'shipping', 'restock'],
  'Jasa / Layanan': ['booking_confirm', 'appointment_remind', 'service_done'],
  'Sekolah / Pendidikan': ['school_info', 'school_payment', 'school_event'],
  'Kantor / Perusahaan': ['meeting_invite', 'company_announce'],
  'F&B / Restoran / Kafe': ['order_ready', 'menu_promo'],
  'Kesehatan / Klinik': ['appointment_health', 'health_promo'],
};

class TemplateFormPage extends ConsumerStatefulWidget {
  const TemplateFormPage({super.key, this.templateId});

  final int? templateId;

  bool get isEditing => templateId != null;

  @override
  ConsumerState<TemplateFormPage> createState() => _TemplateFormPageState();
}

class _TemplateFormPageState extends ConsumerState<TemplateFormPage> {
  final _formKey = GlobalKey<FormState>();
  late final TextEditingController _namaController;
  late final TextEditingController _kontenController;

  String _kategori = 'marketing';
  String? _selectedQuickTemplate;
  bool _isLoading = false;
  bool _isInitialized = false;

  @override
  void initState() {
    super.initState();
    _namaController = TextEditingController();
    _kontenController = TextEditingController();
    _kontenController.addListener(() => setState(() {}));
  }

  @override
  void dispose() {
    _namaController.dispose();
    _kontenController.dispose();
    super.dispose();
  }

  void _populateFromDetail(TemplateDetail t) {
    if (_isInitialized) return;
    _isInitialized = true;
    _namaController.text = t.displayName;
    _kontenController.text = t.body;
    _kategori = t.category;
  }

  void _applyQuickTemplate(String? key) {
    if (key == null) return;
    final t = _quickTemplates[key];
    if (t == null) return;
    setState(() {
      _selectedQuickTemplate = key;
      _namaController.text = t['nama']!;
      _kontenController.text = t['konten']!;
      _kategori = t['kategori']!;
    });
  }

  void _insertVariable(String varName) {
    final text = _kontenController.text;
    final selection = _kontenController.selection;
    final tag = '{{$varName}}';

    if (selection.isValid && selection.start >= 0) {
      final newText = text.replaceRange(selection.start, selection.end, tag);
      _kontenController.value = TextEditingValue(
        text: newText,
        selection: TextSelection.collapsed(offset: selection.start + tag.length),
      );
    } else {
      _kontenController.text = '$text$tag';
      _kontenController.selection = TextSelection.collapsed(
        offset: _kontenController.text.length,
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    if (widget.isEditing) {
      final detailAsync =
          ref.watch(templateDetailProvider(widget.templateId!));
      return detailAsync.when(
        data: (t) {
          _populateFromDetail(t);
          return _buildForm(theme);
        },
        loading: () => Scaffold(
          appBar: AppBar(title: const Text('Edit Template')),
          body: const Center(child: CircularProgressIndicator()),
        ),
        error: (err, _) => Scaffold(
          appBar: AppBar(title: const Text('Edit Template')),
          body: Center(child: Text('Error: $err')),
        ),
      );
    }

    return _buildForm(theme);
  }

  Widget _buildForm(ThemeData theme) {
    return Scaffold(
      appBar: AppBar(
        title: Text(widget.isEditing ? 'Edit Template' : 'Buat Template'),
      ),
      body: Form(
        key: _formKey,
        child: ListView(
          padding: const EdgeInsets.all(20),
          children: [
            // Info banner (same as web)
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                gradient: const LinearGradient(
                  colors: [Color(0xFF6C63FF), Color(0xFF9B59B6)],
                ),
                borderRadius: BorderRadius.circular(12),
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      const Icon(Icons.info_outline, color: Colors.white, size: 18),
                      const SizedBox(width: 8),
                      Text(
                        'Cara Menggunakan Template',
                        style: theme.textTheme.titleSmall?.copyWith(
                          color: Colors.white,
                          fontWeight: FontWeight.bold,
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 8),
                  Text(
                    '1. Buat template baru\n'
                    '2. Submit ke WhatsApp untuk review\n'
                    '3. Tunggu approval dari Meta\n'
                    '4. Sync status di halaman Nomor WhatsApp\n'
                    '5. Template approved bisa dipakai di Campaign',
                    style: theme.textTheme.bodySmall?.copyWith(
                      color: Colors.white.withValues(alpha: 0.9),
                      height: 1.6,
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 20),

            // Quick template (only for create)
            if (!widget.isEditing) ...[
              Text('Mulai dari Template Siap Pakai',
                  style: theme.textTheme.titleSmall),
              const SizedBox(height: 8),
              DropdownButtonFormField<String>(
                value: _selectedQuickTemplate,
                isExpanded: true,
                decoration: const InputDecoration(
                  hintText: '-- Pilih template siap pakai --',
                  prefixIcon: Icon(Icons.flash_on_rounded),
                ),
                items: [
                  for (final group in _quickTemplateGroups.entries) ...[
                    DropdownMenuItem<String>(
                      enabled: false,
                      value: '__group_${group.key}',
                      child: Text(
                        group.key,
                        style: TextStyle(
                          fontWeight: FontWeight.bold,
                          color: theme.colorScheme.primary,
                          fontSize: 13,
                        ),
                      ),
                    ),
                    for (final key in group.value)
                      DropdownMenuItem<String>(
                        value: key,
                        child: Padding(
                          padding: const EdgeInsets.only(left: 12),
                          child: Text(_quickTemplates[key]!['nama']!),
                        ),
                      ),
                  ],
                ],
                onChanged: _applyQuickTemplate,
              ),
              const SizedBox(height: 20),
            ],

            // Nama Template (free text — same as web)
            TextFormField(
              controller: _namaController,
              decoration: const InputDecoration(
                labelText: 'Nama Template *',
                hintText: 'Contoh: Selamat Datang, Promo Diskon',
                helperText: 'Bebas, bisa diubah kapan saja',
              ),
              validator: (v) {
                if (v == null || v.trim().isEmpty) return 'Wajib diisi';
                if (v.length > 100) return 'Maksimal 100 karakter';
                return null;
              },
            ),
            const SizedBox(height: 16),

            // Kategori
            DropdownButtonFormField<String>(
              value: _kategori,
              decoration: const InputDecoration(labelText: 'Kategori *'),
              items: const [
                DropdownMenuItem(
                    value: 'marketing', child: Text('Marketing')),
                DropdownMenuItem(value: 'utility', child: Text('Utility')),
                DropdownMenuItem(
                    value: 'authentication',
                    child: Text('Authentication')),
              ],
              onChanged: (v) => setState(() => _kategori = v ?? 'marketing'),
            ),
            const SizedBox(height: 16),

            // Variable insert buttons (same as web)
            Text('Sisipkan Variabel',
                style: theme.textTheme.titleSmall),
            const SizedBox(height: 8),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                _VariableChip(
                  label: '+ Nama Penerima',
                  tooltip: 'Nama penerima dari data Kontak',
                  onTap: () => _insertVariable('nama'),
                ),
                _VariableChip(
                  label: '+ No HP',
                  tooltip: 'No HP penerima dari data Kontak',
                  onTap: () => _insertVariable('telepon'),
                ),
                _VariableChip(
                  label: '+ Email',
                  tooltip: 'Email penerima dari data Kontak',
                  onTap: () => _insertVariable('email'),
                ),
              ],
            ),
            const SizedBox(height: 12),

            // Konten (body — same as web)
            TextFormField(
              controller: _kontenController,
              decoration: const InputDecoration(
                labelText: 'Isi Template (Konten) *',
                hintText:
                    'Halo {{nama}}, terima kasih sudah berbelanja!',
                helperText:
                    'Gunakan {{nama}}, {{telepon}}, {{email}} untuk variabel. Maks 4096 karakter.',
                alignLabelWithHint: true,
              ),
              maxLines: 8,
              maxLength: 4096,
              validator: (v) {
                if (v == null || v.trim().isEmpty) return 'Wajib diisi';
                return null;
              },
            ),
            const SizedBox(height: 16),

            // Live Preview
            if (_kontenController.text.isNotEmpty) ...[
              Text('Preview', style: theme.textTheme.titleSmall),
              const SizedBox(height: 8),
              Container(
                width: double.infinity,
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  color: const Color(0xFFE7FFDB),
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Text(
                  _kontenController.text
                      .replaceAll('{{nama}}', 'Budi')
                      .replaceAll('{{telepon}}', '08123456789')
                      .replaceAll('{{email}}', 'budi@email.com'),
                  style: theme.textTheme.bodyMedium,
                ),
              ),
              const SizedBox(height: 24),
            ],

            // Submit button
            FilledButton(
              onPressed: _isLoading ? null : _save,
              child: _isLoading
                  ? const SizedBox(
                      width: 20,
                      height: 20,
                      child: CircularProgressIndicator(
                          strokeWidth: 2, color: Colors.white),
                    )
                  : Text(widget.isEditing
                      ? 'Simpan Perubahan'
                      : 'Buat Template'),
            ),
          ],
        ),
      ),
    );
  }

  void _save() async {
    if (!_formKey.currentState!.validate()) return;

    setState(() => _isLoading = true);

    final actionNotifier = ref.read(templateActionProvider.notifier);

    try {
      if (widget.isEditing) {
        final result = await actionNotifier.update(
          widget.templateId!,
          nama: _namaController.text.trim(),
          kategori: _kategori,
          konten: _kontenController.text,
        );

        if (mounted) {
          if (result != null) {
            ScaffoldMessenger.of(context).showSnackBar(
              const SnackBar(content: Text('Template berhasil diupdate')),
            );
            context.pop();
          } else {
            ScaffoldMessenger.of(context).showSnackBar(
              const SnackBar(content: Text('Gagal update template')),
            );
          }
        }
      } else {
        final result = await actionNotifier.create(
          nama: _namaController.text.trim(),
          kategori: _kategori,
          konten: _kontenController.text,
        );

        if (mounted) {
          if (result != null) {
            ScaffoldMessenger.of(context).showSnackBar(
              const SnackBar(content: Text('Template berhasil dibuat')),
            );
            context.pop();
          } else {
            ScaffoldMessenger.of(context).showSnackBar(
              const SnackBar(content: Text('Gagal membuat template')),
            );
          }
        }
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Error: $e')),
        );
      }
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }
}

class _VariableChip extends StatelessWidget {
  const _VariableChip({
    required this.label,
    required this.tooltip,
    required this.onTap,
  });

  final String label;
  final String tooltip;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Tooltip(
      message: tooltip,
      child: ActionChip(
        label: Text(label, style: const TextStyle(fontSize: 12)),
        backgroundColor: Colors.deepPurple.shade50,
        side: BorderSide(color: Colors.deepPurple.shade200),
        onPressed: onTap,
      ),
    );
  }
}
