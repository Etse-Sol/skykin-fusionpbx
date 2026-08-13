import 'package:flutter/material.dart';
import 'package:sip_ua/sip_ua.dart';
import 'package:flutter_webrtc/flutter_webrtc.dart';
import '../utils/theme.dart';

class CallScreen extends StatefulWidget {
  final Call call;
  final SIPUAHelper helper;

  const CallScreen({
    super.key,
    required this.call,
    required this.helper,
  });

  @override
  State<CallScreen> createState() => _CallScreenState();
}

class _CallScreenState extends State<CallScreen> implements SipUaHelperListener {
  String _stateLabel = 'Connecting…';
  bool _isMuted = false;
  bool _isSpeakerOn = false;
  bool _isHeld = false;

  @override
  void initState() {
    super.initState();
    widget.helper.addSipUaHelperListener(this);
    _updateState(widget.call.state);
  }

  @override
  void dispose() {
    widget.helper.removeSipUaHelperListener(this);
    super.dispose();
  }

  void _updateState(CallStateEnum state) {
    switch (state) {
      case CallStateEnum.STREAM:
      case CallStateEnum.ACCEPTED:
        setState(() => _stateLabel = 'Connected');
        break;
      case CallStateEnum.CONNECTING:
        setState(() => _stateLabel = 'Connecting…');
        break;
      case CallStateEnum.PROGRESS:
        setState(() => _stateLabel = 'Ringing…');
        break;
      case CallStateEnum.HOLD:
        setState(() {
          _stateLabel = 'On Hold';
          _isHeld = true;
        });
        break;
      case CallStateEnum.UNHOLD:
        setState(() {
          _stateLabel = 'Connected';
          _isHeld = false;
        });
        break;
      case CallStateEnum.ENDED:
      case CallStateEnum.FAILED:
        setState(() => _stateLabel = 'Call Ended');
        Future.delayed(const Duration(milliseconds: 600), () {
          if (mounted) Navigator.of(context).pop();
        });
        break;
      default:
        break;
    }
  }

  @override
  void callStateChanged(Call call, CallState state) {
    if (call.id == widget.call.id) {
      _updateState(state.state);
    }
  }

  @override void registrationStateChanged(RegistrationState state) {}
  @override void transportStateChanged(TransportState state) {}
  @override void onNewMessage(SIPMessageRequest msg) {}
  @override void onNewNotify(Notify ntf) {}
  @override void onNewReinvite(ReInvite event) {}

  void _toggleMute() {
    setState(() {
      _isMuted = !_isMuted;
      if (_isMuted) {
        widget.call.mute(true, false);
      } else {
        widget.call.unmute(true, false);
      }
    });
  }

  void _toggleHold() {
    if (_isHeld) {
      widget.call.unhold();
    } else {
      widget.call.hold();
    }
  }

  void _toggleSpeaker() {
    setState(() {
      _isSpeakerOn = !_isSpeakerOn;
      Helper.setSpeakerphoneOn(_isSpeakerOn);
    });
  }

  void _hangUp() {
    widget.call.hangup();
  }

  void _accept() {
    widget.call.answer(widget.helper.buildCallOptions(true));
  }

  @override
  Widget build(BuildContext context) {
    final isIncoming = widget.call.direction == Direction.incoming && _stateLabel == 'Ringing…';

    return Scaffold(
      backgroundColor: const Color(0xFF1E293B), // Dark slate in-call screen for focus
      body: SafeArea(
        child: Column(
          children: [
            const SizedBox(height: 60),
            // Caller ID / Name
            Text(
              widget.call.remote_display_name ?? 'Unknown Caller',
              style: const TextStyle(color: Colors.white, fontSize: 28, fontWeight: FontWeight.bold),
            ),
            const SizedBox(height: 8),
            Text(
              widget.call.remote_identity ?? '',
              style: const TextStyle(color: Colors.white60, fontSize: 16),
            ),
            const SizedBox(height: 18),
            // Call State status tag
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
              decoration: BoxDecoration(
                color: Colors.white.withOpacity(0.1),
                borderRadius: BorderRadius.circular(20),
              ),
              child: Text(
                _stateLabel,
                style: const TextStyle(color: Colors.white, fontSize: 13, fontWeight: FontWeight.w600),
              ),
            ),
            const Spacer(),
            
            // Call control buttons grid
            if (!isIncoming) ...[
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 40),
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.spaceEvenly,
                  children: [
                    _controlBtn(
                      icon: _isMuted ? Icons.mic_off_rounded : Icons.mic_rounded,
                      label: 'Mute',
                      active: _isMuted,
                      onTap: _toggleMute,
                    ),
                    _controlBtn(
                      icon: _isHeld ? Icons.play_arrow_rounded : Icons.pause_rounded,
                      label: 'Hold',
                      active: _isHeld,
                      onTap: _toggleHold,
                    ),
                    _controlBtn(
                      icon: _isSpeakerOn ? Icons.volume_up_rounded : Icons.volume_down_rounded,
                      label: 'Speaker',
                      active: _isSpeakerOn,
                      onTap: _toggleSpeaker,
                    ),
                  ],
                ),
              ),
              const SizedBox(height: 48),
            ],

            // Action row (Accept / Decline or Hang Up)
            Padding(
              padding: const EdgeInsets.only(bottom: 60),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  if (isIncoming) ...[
                    // Accept call button
                    GestureDetector(
                      onTap: _accept,
                      child: Container(
                        width: 72,
                        height: 72,
                        decoration: const BoxDecoration(
                          color: SkyKinTheme.success,
                          shape: BoxShape.circle,
                        ),
                        child: const Icon(Icons.call_rounded, color: Colors.white, size: 36),
                      ),
                    ),
                    const SizedBox(width: 48),
                    // Decline call button
                    GestureDetector(
                      onTap: _hangUp,
                      child: Container(
                        width: 72,
                        height: 72,
                        decoration: const BoxDecoration(
                          color: SkyKinTheme.danger,
                          shape: BoxShape.circle,
                        ),
                        child: const Icon(Icons.call_end_rounded, color: Colors.white, size: 36),
                      ),
                    ),
                  ] else ...[
                    // End call button
                    GestureDetector(
                      onTap: _hangUp,
                      child: Container(
                        width: 76,
                        height: 76,
                        decoration: const BoxDecoration(
                          color: SkyKinTheme.danger,
                          shape: BoxShape.circle,
                        ),
                        child: const Icon(Icons.call_end_rounded, color: Colors.white, size: 38),
                      ),
                    ),
                  ],
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _controlBtn({
    required IconData icon,
    required String label,
    required bool active,
    required VoidCallback onTap,
  }) {
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        GestureDetector(
          onTap: onTap,
          child: Container(
            width: 60,
            height: 60,
            decoration: BoxDecoration(
              color: active ? Colors.white : Colors.white.withOpacity(0.1),
              shape: BoxShape.circle,
            ),
            child: Icon(icon, color: active ? const Color(0xFF1E293B) : Colors.white, size: 28),
          ),
        ),
        const SizedBox(height: 8),
        Text(label, style: const TextStyle(color: Colors.white70, fontSize: 12)),
      ],
    );
  }
}
