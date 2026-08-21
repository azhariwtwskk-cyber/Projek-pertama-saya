import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/network/connectivity_service.dart';
import '../../core/theme/app_theme.dart';
import '../../features/sync/application/sync_providers.dart';

/// Persistent top-of-screen strip shown whenever the device is offline or
/// there are items waiting in the outbox (section 26/27). Deliberately
/// unobtrusive — a thin bar, not a blocking dialog — because staff must
/// keep working while offline.
class OfflineBanner extends ConsumerWidget {
  const OfflineBanner({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final isOnline = ref.watch(isOnlineProvider).value ?? true;
    final pendingCount = ref.watch(pendingSyncCountProvider);

    if (isOnline && pendingCount == 0) return const SizedBox.shrink();

    final String text;
    final Color color;
    final IconData icon;
    if (!isOnline) {
      text = pendingCount > 0
          ? "You're offline · $pendingCount item${pendingCount == 1 ? '' : 's'} waiting to sync"
          : "You're offline · changes will sync automatically";
      color = AppColors.textSecondary;
      icon = Icons.cloud_off_rounded;
    } else {
      text =
          '$pendingCount upload${pendingCount == 1 ? '' : 's'} waiting for connection';
      color = AppColors.warning;
      icon = Icons.sync_rounded;
    }

    return Material(
      color: color.withValues(alpha: 0.1),
      child: SafeArea(
        bottom: false,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
          child: Row(
            children: [
              Icon(icon, size: 15, color: color),
              const SizedBox(width: 8),
              Expanded(
                child: Text(text,
                    style: TextStyle(
                        fontSize: 12.5,
                        color: color,
                        fontWeight: FontWeight.w600)),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
