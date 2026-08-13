import 'package:flutter/material.dart';
import 'package:sip_ua/sip_ua.dart';
import '../../utils/theme.dart';

class DialerTab extends StatefulWidget {
  final SIPUAHelper helper;
  final String domain;
  final String extension;
  const DialerTab({super.key, required this.helper, required this.domain, required this.extension});
  @override State<DialerTab> createState() => _DialerTabState();
}

class _DialerTabState extends State<DialerTab> {
  final _ctrl = TextEditingController();

  void _press(String digit) {
    setState(() => _ctrl.text += digit);
    // Send DTMF if call active
  }

  void _delete() {
    if (_ctrl.text.isNotEmpty) setState(() => _ctrl.text = _ctrl.text.substring(0, _ctrl.text.length - 1));
  }

  void _call() {
    final num = _ctrl.text.trim();
    if (num.isEmpty) return;
    final target = num.contains('@') ? num : 'sip:$num@${widget.domain}';
    widget.helper.call(target, voiceOnly: true);
  }

  @override
  Widget build(BuildContext context) {
    const digits = ['1','2','3','4','5','6','7','8','9','*','0','#'];
    const sub    = ['','ABC','DEF','GHI','JKL','MNO','PQRS','TUV','WXYZ','','+',''];

    return Column(
      children: [
        // Number display
        Container(
          color: Colors.white,
          padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 20),
          child: Row(
            children: [
              Expanded(
                child: TextField(
                  controller: _ctrl,
                  readOnly: true,
                  textAlign: TextAlign.center,
                  style: const TextStyle(fontSize: 30, fontWeight: FontWeight.w600, letterSpacing: 2, color: SkyKinTheme.textPrimary),
                  decoration: const InputDecoration(border: InputBorder.none, hintText: 'Enter number', hintStyle: TextStyle(color: SkyKinTheme.textMuted, fontSize: 22)),
                ),
              ),
              if (_ctrl.text.isNotEmpty)
                IconButton(icon: const Icon(Icons.backspace_outlined, color: SkyKinTheme.textMuted), onPressed: _delete),
            ],
          ),
        ),
        const Divider(height: 1),

        // Dialpad grid
        Expanded(
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 32, vertical: 16),
            child: GridView.builder(
              physics: const NeverScrollableScrollPhysics(),
              shrinkWrap: true,
              gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                crossAxisCount: 3,
                mainAxisSpacing: 16,
                crossAxisSpacing: 16,
                childAspectRatio: 1.4,
              ),
              itemCount: digits.length,
              itemBuilder: (ctx, i) => _DialKey(
                digit: digits[i],
                sub: sub[i],
                onTap: () => _press(digits[i]),
              ),
            ),
          ),
        ),

        // Call button
        Padding(
          padding: const EdgeInsets.only(bottom: 32),
          child: GestureDetector(
            onTap: _call,
            child: Container(
              width: 72,
              height: 72,
              decoration: BoxDecoration(
                gradient: const LinearGradient(colors: [Color(0xFF28A745), Color(0xFF20C997)], begin: Alignment.topLeft, end: Alignment.bottomRight),
                shape: BoxShape.circle,
                boxShadow: [BoxShadow(color: Colors.green.withOpacity(0.4), blurRadius: 16, offset: const Offset(0, 6))],
              ),
              child: const Icon(Icons.call_rounded, color: Colors.white, size: 34),
            ),
          ),
        ),
      ],
    );
  }
}

class _DialKey extends StatelessWidget {
  final String digit;
  final String sub;
  final VoidCallback onTap;
  const _DialKey({required this.digit, required this.sub, required this.onTap});

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(14),
      child: Container(
        decoration: BoxDecoration(
          color: const Color(0xFFF5F7FA),
          borderRadius: BorderRadius.circular(14),
          border: Border.all(color: const Color(0xFFE9ECEF)),
        ),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Text(digit, style: const TextStyle(fontSize: 24, fontWeight: FontWeight.w600, color: SkyKinTheme.textPrimary)),
            if (sub.isNotEmpty)
              Text(sub, style: const TextStyle(fontSize: 9, color: SkyKinTheme.textMuted, letterSpacing: 1.5)),
          ],
        ),
      ),
    );
  }
}
