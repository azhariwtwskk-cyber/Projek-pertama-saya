import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/theme/app_theme.dart';
import '../../../shared/widgets/section_header.dart';
import '../../auth/application/auth_providers.dart';
import '../../sync/presentation/sync_centre_screen.dart';

/// Section 28: premium profile screen with the full settings list.
class ProfileScreen extends ConsumerWidget {
  const ProfileScreen({super.key});

  Future<void> _confirmLogout(BuildContext context, WidgetRef ref) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        title: const Text('Log Out'),
        content: const Text('Are you sure you want to log out of CPMSPro Workforce?'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Cancel')),
          TextButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Log Out', style: TextStyle(color: AppColors.danger)),
          ),
        ],
      ),
    );
    if (confirmed == true) {
      await ref.read(authControllerProvider.notifier).logout();
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final user = ref.watch(currentStaffUserProvider);
    if (user == null) return const SizedBox.shrink();

    return Scaffold(
      appBar: AppBar(title: const Text('Profile')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          AppSectionCard(
            child: Column(
              children: [
                CircleAvatar(
                  radius: 40,
                  backgroundColor: Theme.of(context).colorScheme.primary.withValues(alpha: 0.12),
                  backgroundImage: user.profileImageUrl.isNotEmpty ? NetworkImage(user.profileImageUrl) : null,
                  child: user.profileImageUrl.isEmpty
                      ? Text(user.name.isNotEmpty ? user.name[0] : '?',
                          style: TextStyle(fontSize: 28, fontWeight: FontWeight.w800, color: Theme.of(context).colorScheme.primary))
                      : null,
                ),
                const SizedBox(height: 12),
                Text(user.name, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 18)),
                const SizedBox(height: 2),
                Text(user.role, style: const TextStyle(color: AppColors.textSecondary)),
                const SizedBox(height: 16),
                const Divider(),
                const SizedBox(height: 8),
                _InfoRow(icon: Icons.badge_outlined, label: 'Employee ID', value: user.employeeId),
                _InfoRow(icon: Icons.apartment_rounded, label: 'Property', value: user.branding.propertyName),
                _InfoRow(icon: Icons.phone_outlined, label: 'Phone', value: user.phone.isEmpty ? '—' : user.phone),
                _InfoRow(icon: Icons.email_outlined, label: 'Email', value: user.email.isEmpty ? '—' : user.email),
              ],
            ),
          ),
          const SizedBox(height: 20),
          _SettingsGroup(items: [
            _SettingsItem(icon: Icons.edit_outlined, label: 'Edit Profile', onTap: () {}),
            _SettingsItem(icon: Icons.lock_reset_rounded, label: 'Change Password', onTap: () {}),
            _SettingsItem(icon: Icons.language_rounded, label: 'Language', trailing: 'English', onTap: () {}),
            _SettingsItem(
              icon: Icons.sync_rounded,
              label: 'Sync Centre',
              onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const SyncCentreScreen())),
            ),
            _SettingsItem(icon: Icons.notifications_none_rounded, label: 'Notification Settings', onTap: () {}),
            _SettingsItem(icon: Icons.settings_outlined, label: 'App Settings', onTap: () {}),
          ]),
          const SizedBox(height: 16),
          _SettingsGroup(items: [
            _SettingsItem(icon: Icons.help_outline_rounded, label: 'Help & Support', onTap: () {}),
            _SettingsItem(icon: Icons.privacy_tip_outlined, label: 'Privacy', onTap: () {}),
            _SettingsItem(icon: Icons.info_outline_rounded, label: 'About CPMSPro', onTap: () {}),
          ]),
          const SizedBox(height: 16),
          _SettingsGroup(items: [
            _SettingsItem(icon: Icons.logout_rounded, label: 'Logout', destructive: true, onTap: () => _confirmLogout(context, ref)),
          ]),
        ],
      ),
    );
  }
}

class _InfoRow extends StatelessWidget {
  const _InfoRow({required this.icon, required this.label, required this.value});
  final IconData icon;
  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(
        children: [
          Icon(icon, size: 18, color: AppColors.textSecondary),
          const SizedBox(width: 10),
          Expanded(child: Text(label, style: const TextStyle(color: AppColors.textSecondary))),
          Text(value, style: const TextStyle(fontWeight: FontWeight.w600)),
        ],
      ),
    );
  }
}

class _SettingsGroup extends StatelessWidget {
  const _SettingsGroup({required this.items});
  final List<_SettingsItem> items;

  @override
  Widget build(BuildContext context) {
    return AppSectionCard(
      padding: EdgeInsets.zero,
      child: Column(
        children: [
          for (var i = 0; i < items.length; i++) ...[
            items[i],
            if (i != items.length - 1) const Divider(height: 1, indent: 56),
          ],
        ],
      ),
    );
  }
}

class _SettingsItem extends StatelessWidget {
  const _SettingsItem({required this.icon, required this.label, required this.onTap, this.trailing, this.destructive = false});
  final IconData icon;
  final String label;
  final VoidCallback onTap;
  final String? trailing;
  final bool destructive;

  @override
  Widget build(BuildContext context) {
    final color = destructive ? AppColors.danger : AppColors.textPrimary;
    return ListTile(
      onTap: onTap,
      leading: Icon(icon, color: destructive ? AppColors.danger : AppColors.textSecondary),
      title: Text(label, style: TextStyle(color: color, fontWeight: FontWeight.w600)),
      trailing: trailing != null
          ? Text(trailing!, style: const TextStyle(color: AppColors.textSecondary))
          : const Icon(Icons.chevron_right_rounded, color: AppColors.textSecondary),
    );
  }
}
