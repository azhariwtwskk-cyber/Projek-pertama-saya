import 'package:intl/intl.dart';

class AppFormatters {
  const AppFormatters._();

  static final DateFormat friendlyDate = DateFormat('EEEE, d MMMM');
  static final DateFormat shortDate = DateFormat('d MMM yyyy');
  static final DateFormat time12h = DateFormat('h:mm a');
  static final DateFormat monthYear = DateFormat('MMMM yyyy');

  static String greetingForNow(DateTime now) {
    final hour = now.hour;
    if (hour < 12) return 'Good Morning';
    if (hour < 17) return 'Good Afternoon';
    return 'Good Evening';
  }

  static String duration(Duration d) {
    final h = d.inHours;
    final m = d.inMinutes.remainder(60);
    if (h == 0) return '${m}m';
    return '${h}h ${m}m';
  }

  static String timeAgo(DateTime dateTime) {
    final diff = DateTime.now().difference(dateTime);
    if (diff.inSeconds < 60) return 'Just now';
    if (diff.inMinutes < 60) return '${diff.inMinutes} minutes ago';
    if (diff.inHours < 24) return '${diff.inHours} hours ago';
    if (diff.inDays < 7) return '${diff.inDays} days ago';
    return shortDate.format(dateTime);
  }
}
