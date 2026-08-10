import 'package:flutter/material.dart';
import '../../utils/theme.dart';

class SettingsTab extends StatelessWidget {
  final String extension;
  final String domain;
  final String serverIp;
  final VoidCallback onSignOut;

  const SettingsTab({
    super.key,
    required this.extension,
    required this.domain,
    required this.serverIp,
    required this.onSignOut,
  });

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.all(24),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          // Agent details card
          Card(
            child: Padding(
              padding: const EdgeInsets.all(20),
              child: Column(
                children: [
                  const CircleAvatar(
                    radius: 36,
                    backgroundColor: SkyKinTheme.primaryBlue,
                    child: Icon(Icons.person, size: 40, color: Colors.white),
                  ),
                  const SizedBox(height: 14),
                  const Text(
                    'Active Extension',
                    style: TextStyle(fontSize: 13, color: SkyKinTheme.textMuted, fontWeight: FontWeight.w600),
                  ),
                  Text(
                    extension,
                    style: const TextStyle(fontSize: 28, fontWeight: FontWeight.bold, color: SkyKinTheme.textPrimary),
                  ),
                  const SizedBox(height: 16),
                  const Divider(),
                  const SizedBox(height: 8),
                  _infoRow('SIP Server', serverIp),
                  _infoRow('SIP Domain', domain),
                  _infoRow('WebSocket URL', 'wss://$domain:7443'),
                ],
              ),
            ),
          ),
          const Spacer(),
          // Logout button
          SizedBox(
            height: 48,
            child: OutlinedButton.icon(
              onPressed: onSignOut,
              style: OutlinedButton.styleFrom(
                side: const BorderSide(color: SkyKinTheme.danger),
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                foregroundColor: SkyKinTheme.danger,
              ),
              icon: const Icon(Icons.logout_rounded, size: 20),
              label: const Text('Sign Out Agent', style: TextStyle(fontWeight: FontWeight.w700)),
            ),
          ),
        ],
      ),
    );
  }

  Widget _infoRow(String label, String value) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(label, style: const TextStyle(color: SkyKinTheme.textMuted, fontSize: 13)),
          Text(value, style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13, color: SkyKinTheme.textPrimary)),
        ],
      ),
    );
  }
}
