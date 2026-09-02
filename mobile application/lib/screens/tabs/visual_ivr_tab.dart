import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'dart:convert';
import 'package:sip_ua/sip_ua.dart';
import '../../utils/theme.dart';

class VisualIvrTab extends StatefulWidget {
  final SIPUAHelper helper;
  final String serverIp;

  const VisualIvrTab({
    super.key,
    required this.helper,
    required this.serverIp,
  });

  @override
  State<VisualIvrTab> createState() => _VisualIvrTabState();
}

class _VisualIvrTabState extends State<VisualIvrTab> {
  bool _isLoading = false;
  bool _isUsingMock = false;

  // Current menu data
  String _currentMenuId = 'root';
  String _menuTitle = 'Visual IVR';
  String _menuDescription = 'Select an option to connect';
  List<dynamic> _menuItems = [];

  // History stack for back navigation
  final List<Map<String, dynamic>> _navigationHistory = [];

  @override
  void initState() {
    super.initState();
    _fetchMenu(_currentMenuId);
  }

  Future<void> _fetchMenu(String menuId) async {
    setState(() {
      _isLoading = true;
      _currentMenuId = menuId;
    });

    try {
      // Fetch from FastAPI backend menu router
      final response = await http.get(
        Uri.parse('http://${widget.serverIp}:8000/api/menu/$menuId'),
      ).timeout(const Duration(seconds: 4));

      if (response.statusCode == 200) {
        final data = json.decode(response.body);
        setState(() {
          _menuTitle = data['title'] ?? 'Visual IVR';
          _menuDescription = data['description'] ?? '';
          _menuItems = data['items'] ?? [];
          _isUsingMock = false;
          _isLoading = false;
        });
      } else {
        throw Exception('Server returned status code: ${response.statusCode}');
      }
    } catch (e) {
      debugPrint('[SkyKin IVR Error] Failed to fetch menu: $e');
      _loadMockData(menuId);
    }
  }

  // Fallback Mock Data so the application works even if backend is offline/unfinished
  void _loadMockData(String menuId) {
    Map<String, dynamic> mockResponse;

    if (menuId == 'root') {
      mockResponse = {
        'id': 'root',
        'title': 'SkyKin Help Center (Offline)',
        'description': 'Connection failed. Showing offline template menu.',
        'items': [
          {
            'id': 'mock_sales',
            'title': 'Sales Department',
            'description': 'Speak to a representative about pricing and options.',
            'icon': 'storefront',
            'type': 'call',
            'value': '1001',
          },
          {
            'id': 'mock_support_menu',
            'title': 'Customer Support',
            'description': 'Billing questions, Technical help, or Account options.',
            'icon': 'support_agent',
            'type': 'submenu',
            'value': 'mock_support',
          },
          {
            'id': 'mock_directory',
            'title': 'Staff Directory',
            'description': 'Connect with a specific agent directly.',
            'icon': 'contact_phone',
            'type': 'call',
            'value': '1000',
          }
        ]
      };
    } else if (menuId == 'mock_support') {
      mockResponse = {
        'id': 'mock_support',
        'title': 'Customer Support Options',
        'description': 'Choose the area you need help with.',
        'items': [
          {
            'id': 'mock_tech_support',
            'title': 'Technical Support',
            'description': 'Troubleshooting or configuration issues.',
            'icon': 'build',
            'type': 'call',
            'value': '1002',
          },
          {
            'id': 'mock_billing',
            'title': 'Billing & Accounts',
            'description': 'Invoices, payments, and account status.',
            'icon': 'payment',
            'type': 'call',
            'value': '1003',
          }
        ]
      };
    } else {
      mockResponse = {
        'id': menuId,
        'title': 'Sub-menu: $menuId',
        'description': 'Generic offline fallback sub-menu.',
        'items': [
          {
            'id': 'mock_back_call',
            'title': 'Return to Operator',
            'description': 'Dial the main front desk extension.',
            'icon': 'phone',
            'type': 'call',
            'value': '1000',
          }
        ]
      };
    }

    setState(() {
      _menuTitle = mockResponse['title'];
      _menuDescription = mockResponse['description'];
      _menuItems = mockResponse['items'];
      _isUsingMock = true;
      _isLoading = false;
    });
  }

  void _onItemTapped(Map<String, dynamic> item) {
    final type = item['type'] ?? '';
    final value = item['value'] ?? '';

    if (type == 'call') {
      _makeSipCall(value);
    } else if (type == 'submenu') {
      // Save current state to history
      _navigationHistory.add({
        'id': _currentMenuId,
        'title': _menuTitle,
        'description': _menuDescription,
        'items': _menuItems,
      });
      _fetchMenu(value);
    }
  }

  void _navigateBack() {
    if (_navigationHistory.isNotEmpty) {
      final previousState = _navigationHistory.removeLast();
      setState(() {
        _currentMenuId = previousState['id'];
        _menuTitle = previousState['title'];
        _menuDescription = previousState['description'];
        _menuItems = previousState['items'];
      });
    }
  }

  void _makeSipCall(String destination) {
    if (destination.isEmpty) return;

    // Show a confirmation dialog before dialing
    showDialog(
      context: context,
      builder: (BuildContext context) {
        return AlertDialog(
          title: const Text('Start Call'),
          content: Text('Would you like to dial extension $destination natively?'),
          actions: [
            TextButton(
              child: const Text('Cancel'),
              onPressed: () => Navigator.of(context).pop(),
            ),
            ElevatedButton(
              style: ElevatedButton.styleFrom(
                backgroundColor: SkyKinTheme.primaryBlue,
                foregroundColor: Colors.white,
              ),
              child: const Text('Call'),
              onPressed: () {
                Navigator.of(context).pop();
                widget.helper.call(destination, voiceOnly: true);
              },
            ),
          ],
        );
      },
    );
  }

  IconData _getIconData(String? iconName) {
    switch (iconName?.toLowerCase()) {
      case 'storefront':
      case 'store':
        return Icons.storefront_rounded;
      case 'support_agent':
      case 'agent':
        return Icons.support_agent_rounded;
      case 'contact_phone':
      case 'directory':
        return Icons.contact_phone_rounded;
      case 'build':
      case 'tool':
      case 'tech':
        return Icons.build_rounded;
      case 'payment':
      case 'billing':
      case 'credit':
        return Icons.payment_rounded;
      case 'help':
      case 'faq':
        return Icons.help_outline_rounded;
      case 'settings':
        return Icons.settings_rounded;
      default:
        return Icons.keyboard_arrow_right_rounded;
    }
  }

  @override
  Widget build(BuildContext context) {
    final canGoBack = _navigationHistory.isNotEmpty;

    return Scaffold(
      body: RefreshIndicator(
        onRefresh: () => _fetchMenu(_currentMenuId),
        child: Column(
          children: [
            // Connection status banner if using mock fallback
            if (_isUsingMock)
              Container(
                color: SkyKinTheme.warning.withValues(alpha: 0.15),
                padding: const EdgeInsets.symmetric(vertical: 6, horizontal: 16),
                width: double.infinity,
                child: Row(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    const Icon(Icons.wifi_off_rounded, color: SkyKinTheme.warning, size: 16),
                    const SizedBox(width: 8),
                    Text(
                      'Offline Mode: Backend at ${widget.serverIp} unreachable',
                      style: const TextStyle(
                        color: SkyKinTheme.warning,
                        fontSize: 12,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                  ],
                ),
              ),

            // Dynamic Page Header with Back Navigation
            Padding(
              padding: const EdgeInsets.all(16.0),
              child: Row(
                children: [
                  if (canGoBack)
                    IconButton(
                      icon: const Icon(Icons.arrow_back_ios_new_rounded),
                      onPressed: _navigateBack,
                    ),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          _menuTitle,
                          style: const TextStyle(
                            fontSize: 20,
                            fontWeight: FontWeight.w800,
                            color: SkyKinTheme.textPrimary,
                          ),
                        ),
                        if (_menuDescription.isNotEmpty) ...[
                          const SizedBox(height: 4),
                          Text(
                            _menuDescription,
                            style: const TextStyle(
                              fontSize: 13,
                              color: SkyKinTheme.textMuted,
                            ),
                          ),
                        ]
                      ],
                    ),
                  ),
                ],
              ),
            ),

            // Items List
            Expanded(
              child: _isLoading
                  ? const Center(child: CircularProgressIndicator())
                  : _menuItems.isEmpty
                      ? const Center(
                          child: Text(
                            'No items available in this menu',
                            style: TextStyle(color: SkyKinTheme.textMuted),
                          ),
                        )
                      : ListView.builder(
                          padding: const EdgeInsets.symmetric(horizontal: 16.0),
                          itemCount: _menuItems.length,
                          itemBuilder: (context, index) {
                            final item = _menuItems[index] as Map<String, dynamic>;
                            final type = item['type'] ?? '';
                            final isSubmenu = type == 'submenu';

                            return Card(
                              margin: const EdgeInsets.only(bottom: 12.0),
                              child: InkWell(
                                borderRadius: BorderRadius.circular(12),
                                onTap: () => _onItemTapped(item),
                                child: Padding(
                                  padding: const EdgeInsets.all(16.0),
                                  child: Row(
                                    children: [
                                      // Icon container
                                      Container(
                                        padding: const EdgeInsets.all(10),
                                        decoration: BoxDecoration(
                                          color: (isSubmenu
                                                  ? SkyKinTheme.accentCyan
                                                  : SkyKinTheme.primaryBlue)
                                              .withValues(alpha: 0.1),
                                          shape: BoxShape.circle,
                                        ),
                                        child: Icon(
                                          _getIconData(item['icon']),
                                          color: isSubmenu
                                              ? SkyKinTheme.accentCyan
                                              : SkyKinTheme.primaryBlue,
                                          size: 24,
                                        ),
                                      ),
                                      const SizedBox(width: 16),
                                      // Text Details
                                      Expanded(
                                        child: Column(
                                          crossAxisAlignment: CrossAxisAlignment.start,
                                          children: [
                                            Text(
                                              item['title'] ?? 'Option',
                                              style: const TextStyle(
                                                fontSize: 15,
                                                fontWeight: FontWeight.bold,
                                                color: SkyKinTheme.textPrimary,
                                              ),
                                            ),
                                            if (item['description'] != null) ...[
                                              const SizedBox(height: 2),
                                              Text(
                                                item['description'],
                                                style: const TextStyle(
                                                  fontSize: 12,
                                                  color: SkyKinTheme.textMuted,
                                                ),
                                              ),
                                            ],
                                          ],
                                        ),
                                      ),
                                      const SizedBox(width: 8),
                                      // Action Indicator
                                      Icon(
                                        isSubmenu
                                            ? Icons.arrow_forward_ios_rounded
                                            : Icons.phone_in_talk_rounded,
                                        color: isSubmenu
                                            ? SkyKinTheme.textMuted
                                            : SkyKinTheme.success,
                                        size: 16,
                                      ),
                                    ],
                                  ),
                                ),
                              ),
                            );
                          },
                        ),
            ),
          ],
        ),
      ),
    );
  }
}
