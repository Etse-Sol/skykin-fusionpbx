import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import '../../utils/theme.dart';

class SmsTab extends StatefulWidget {
  final String serverIp;
  const SmsTab({super.key, required this.serverIp});
  @override State<SmsTab> createState() => _SmsTabState();
}

class _SmsTabState extends State<SmsTab> {
  List<Map<String, dynamic>> _logs = [];
  bool _loading = true;
  bool _sending = false;
  String? _error;
  final _toCtrl  = TextEditingController();
  final _msgCtrl = TextEditingController();

  @override
  void initState() { super.initState(); _loadLogs(); }

  @override
  void dispose() { _toCtrl.dispose(); _msgCtrl.dispose(); super.dispose(); }

  Future<void> _loadLogs() async {
    setState(() { _loading = true; _error = null; });
    try {
      final url = Uri.parse('http://${widget.serverIp}:8000/app/mobile_api/index.php?action=sms_logs');
      final resp = await http.get(url).timeout(const Duration(seconds: 10));
      final data = jsonDecode(resp.body);
      if (data is List) {
        setState(() => _logs = data.cast<Map<String, dynamic>>());
      } else {
        setState(() => _error = data['error'] ?? 'Failed to load SMS history.');
      }
    } catch (e) {
      setState(() => _error = 'Connection error: $e');
    } finally {
      setState(() => _loading = false);
    }
  }

  Future<void> _sendSms() async {
    final to = _toCtrl.text.trim();
    final msg = _msgCtrl.text.trim();
    if (to.isEmpty || msg.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Please fill in recipient and message.'), backgroundColor: SkyKinTheme.danger));
      return;
    }
    setState(() => _sending = true);
    try {
      final url = Uri.parse('http://${widget.serverIp}:8000/app/mobile_api/index.php?action=send_sms');
      final resp = await http.post(url,
        headers: {'Content-Type': 'application/json'},
        body: jsonEncode({'to': to, 'message': msg}),
      ).timeout(const Duration(seconds: 15));
      final data = jsonDecode(resp.body) as Map<String, dynamic>;
      if (resp.statusCode == 200 && data['status'] == 'success') {
        _toCtrl.clear(); _msgCtrl.clear();
        if (!mounted) return;
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('SMS sent successfully!'), backgroundColor: SkyKinTheme.success));
        await _loadLogs();
      } else {
        if (!mounted) return;
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(data['error'] ?? 'Failed to send SMS.'), backgroundColor: SkyKinTheme.danger));
      }
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('Error: $e'), backgroundColor: SkyKinTheme.danger));
    } finally {
      setState(() => _sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        // Compose panel
        Container(
          color: Colors.white,
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const Text('New SMS', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 14, color: SkyKinTheme.primaryBlue)),
              const SizedBox(height: 10),
              TextField(
                controller: _toCtrl,
                keyboardType: TextInputType.phone,
                decoration: _dec('Recipient number (e.g. +251...)', Icons.person_outline_rounded),
              ),
              const SizedBox(height: 10),
              TextField(
                controller: _msgCtrl,
                maxLines: 3,
                decoration: _dec('Message text…', Icons.message_outlined),
              ),
              const SizedBox(height: 10),
              SizedBox(
                height: 44,
                child: ElevatedButton.icon(
                  onPressed: _sending ? null : _sendSms,
                  style: ElevatedButton.styleFrom(backgroundColor: SkyKinTheme.primaryBlue, shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10))),
                  icon: _sending ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2)) : const Icon(Icons.send_rounded, color: Colors.white, size: 18),
                  label: Text(_sending ? 'Sending…' : 'Send SMS', style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w700)),
                ),
              ),
            ],
          ),
        ),
        const Divider(height: 1),

        // History
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 6),
          child: Row(children: [
            const Text('Sent History', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13, color: SkyKinTheme.textMuted)),
            const Spacer(),
            TextButton.icon(onPressed: _loadLogs, icon: const Icon(Icons.refresh, size: 16), label: const Text('Refresh'), style: TextButton.styleFrom(foregroundColor: SkyKinTheme.primaryBlue)),
          ]),
        ),

        Expanded(
          child: _loading
              ? const Center(child: CircularProgressIndicator(color: SkyKinTheme.primaryBlue))
              : _logs.isEmpty
                  ? const Center(child: Text('No SMS messages sent yet.', style: TextStyle(color: SkyKinTheme.textMuted)))
                  : ListView.separated(
                      padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
                      itemCount: _logs.length,
                      separatorBuilder: (_, __) => const SizedBox(height: 6),
                      itemBuilder: (ctx, i) {
                        final log = _logs[i];
                        final ok = (log['status'] ?? '').toString().toLowerCase() == 'sent';
                        return Card(
                          child: ListTile(
                            contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
                            leading: CircleAvatar(
                              backgroundColor: ok ? SkyKinTheme.success.withOpacity(0.1) : SkyKinTheme.danger.withOpacity(0.1),
                              child: Icon(Icons.sms_rounded, color: ok ? SkyKinTheme.success : SkyKinTheme.danger, size: 20),
                            ),
                            title: Text(log['phone_number'] ?? '', style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
                            subtitle: Text(log['message'] ?? '', maxLines: 2, overflow: TextOverflow.ellipsis,
                              style: const TextStyle(color: SkyKinTheme.textMuted, fontSize: 12)),
                            trailing: Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.end, children: [
                              Container(
                                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                                decoration: BoxDecoration(color: ok ? SkyKinTheme.success.withOpacity(0.1) : SkyKinTheme.danger.withOpacity(0.1), borderRadius: BorderRadius.circular(6)),
                                child: Text(ok ? 'Sent' : 'Failed', style: TextStyle(color: ok ? SkyKinTheme.success : SkyKinTheme.danger, fontSize: 10, fontWeight: FontWeight.w700)),
                              ),
                            ]),
                          ),
                        );
                      },
                    ),
        ),
      ],
    );
  }

  InputDecoration _dec(String hint, IconData icon) => InputDecoration(
    hintText: hint, hintStyle: const TextStyle(color: SkyKinTheme.textMuted, fontSize: 13),
    prefixIcon: Icon(icon, color: SkyKinTheme.primaryBlue, size: 18),
    filled: true, fillColor: const Color(0xFFF5F7FA),
    contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
    border: OutlineInputBorder(borderRadius: BorderRadius.circular(10), borderSide: BorderSide.none),
    focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(10), borderSide: const BorderSide(color: SkyKinTheme.primaryBlue, width: 1.5)),
  );
}
