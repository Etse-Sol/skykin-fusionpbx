import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:audioplayers/audioplayers.dart';
import '../../utils/theme.dart';

class VoicemailTab extends StatefulWidget {
  final String extension;
  final String serverIp;
  const VoicemailTab({super.key, required this.extension, required this.serverIp});
  @override State<VoicemailTab> createState() => _VoicemailTabState();
}

class _VoicemailTabState extends State<VoicemailTab> {
  List<Map<String, dynamic>> _messages = [];
  bool _loading = true;
  String? _error;
  final AudioPlayer _player = AudioPlayer();
  String? _playingUuid;
  bool _isPlaying = false;

  @override
  void initState() {
    super.initState();
    _load();
    _player.onPlayerStateChanged.listen((s) {
      setState(() => _isPlaying = s == PlayerState.playing);
      if (s == PlayerState.completed) setState(() => _playingUuid = null);
    });
  }

  @override
  void dispose() {
    _player.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() { _loading = true; _error = null; });
    try {
      final url = Uri.parse(
        'http://${widget.serverIp}:8000/app/mobile_api/index.php?action=voicemails&extension=${widget.extension}');
      final resp = await http.get(url).timeout(const Duration(seconds: 10));
      final data = jsonDecode(resp.body);
      if (data is List) {
        setState(() => _messages = data.cast<Map<String, dynamic>>());
      } else {
        setState(() => _error = data['error'] ?? 'Failed to load voicemails.');
      }
    } catch (e) {
      setState(() => _error = 'Connection error: $e');
    } finally {
      setState(() => _loading = false);
    }
  }

  Future<void> _togglePlay(Map<String, dynamic> msg) async {
    final uuid = msg['uuid'] as String;
    if (_playingUuid == uuid && _isPlaying) {
      await _player.pause();
    } else {
      setState(() => _playingUuid = uuid);
      await _player.play(UrlSource(msg['download_url'] as String));
    }
  }

  String _formatDuration(int secs) {
    final m = secs ~/ 60;
    final s = secs % 60;
    return '${m.toString().padLeft(2,'0')}:${s.toString().padLeft(2,'0')}';
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) return const Center(child: CircularProgressIndicator(color: SkyKinTheme.primaryBlue));
    if (_error != null) return Center(child: Column(mainAxisSize: MainAxisSize.min, children: [
      const Icon(Icons.error_outline, color: SkyKinTheme.danger, size: 48),
      const SizedBox(height: 12),
      Text(_error!, style: const TextStyle(color: SkyKinTheme.textMuted)),
      const SizedBox(height: 16),
      ElevatedButton(onPressed: _load, style: ElevatedButton.styleFrom(backgroundColor: SkyKinTheme.primaryBlue), child: const Text('Retry', style: TextStyle(color: Colors.white))),
    ]));

    if (_messages.isEmpty) return const Center(child: Column(mainAxisSize: MainAxisSize.min, children: [
      Icon(Icons.voicemail_rounded, size: 64, color: SkyKinTheme.textMuted),
      SizedBox(height: 12),
      Text('No voicemail messages', style: TextStyle(color: SkyKinTheme.textMuted, fontSize: 16)),
    ]));

    return RefreshIndicator(
      onRefresh: _load,
      color: SkyKinTheme.primaryBlue,
      child: ListView.separated(
        padding: const EdgeInsets.all(16),
        itemCount: _messages.length,
        separatorBuilder: (_, __) => const SizedBox(height: 8),
        itemBuilder: (ctx, i) {
          final msg = _messages[i];
          final uuid = msg['uuid'] as String;
          final isNew = (msg['status'] ?? 'New') == 'New';
          final playing = _playingUuid == uuid;

          return Card(
            child: ListTile(
              contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
              leading: CircleAvatar(
                backgroundColor: isNew ? SkyKinTheme.primaryBlue.withOpacity(0.1) : SkyKinTheme.background,
                child: Icon(Icons.voicemail_rounded, color: isNew ? SkyKinTheme.primaryBlue : SkyKinTheme.textMuted),
              ),
              title: Row(children: [
                if (isNew) Container(
                  margin: const EdgeInsets.only(right: 8),
                  padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                  decoration: BoxDecoration(color: SkyKinTheme.primaryBlue, borderRadius: BorderRadius.circular(4)),
                  child: const Text('NEW', style: TextStyle(color: Colors.white, fontSize: 9, fontWeight: FontWeight.w700)),
                ),
                Expanded(child: Text(msg['caller_name'] ?? 'Unknown', style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 14), overflow: TextOverflow.ellipsis)),
              ]),
              subtitle: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const SizedBox(height: 3),
                  Text(msg['caller_num'] ?? '', style: const TextStyle(color: SkyKinTheme.textMuted, fontSize: 12)),
                  Text('${msg['date']} · ${_formatDuration((msg['duration'] ?? 0) as int)}',
                    style: const TextStyle(color: SkyKinTheme.textMuted, fontSize: 11)),
                  if (playing) ...[
                    const SizedBox(height: 6),
                    LinearProgressIndicator(color: SkyKinTheme.primaryBlue, backgroundColor: SkyKinTheme.background,
                      minHeight: 3, borderRadius: BorderRadius.circular(4)),
                  ],
                ],
              ),
              trailing: IconButton(
                icon: AnimatedSwitcher(
                  duration: const Duration(milliseconds: 200),
                  child: Icon(
                    playing && _isPlaying ? Icons.pause_circle_filled_rounded : Icons.play_circle_filled_rounded,
                    key: ValueKey('$uuid-${playing && _isPlaying}'),
                    color: SkyKinTheme.primaryBlue, size: 36,
                  ),
                ),
                onPressed: () => _togglePlay(msg),
              ),
            ),
          );
        },
      ),
    );
  }
}
