export const APP_CONFIG = Object.freeze({
  name: 'CPMS Workforce',
  version: '4.1.1',
  apiBaseUrl: '/cpms/api/v1',
  unifiedWebDashboard: true,
  demoMode: false,
  requestTimeoutMs: 15000,
  supportedRoles: Object.freeze(['staff', 'security']),
});

export const DEMO_ACCOUNTS = Object.freeze({
  'staff.demo': Object.freeze({
    password: 'Demo123!',
    role: 'staff',
    user: Object.freeze({ id: 1001, name: 'Ahmad Razak' }),
  }),
  'security.demo': Object.freeze({
    password: 'Demo123!',
    role: 'security',
    user: Object.freeze({ id: 2001, name: 'Mohd Rizal' }),
  }),
});

export const DEMO_PROPERTY = Object.freeze({
  id: 1,
  code: 'V23',
  name: 'V23 Malawa Ria Apartment',
});

export const DEMO_DASHBOARDS = Object.freeze({
  staff: Object.freeze({
    shift: '8:00 AM – 5:00 PM',
    attendanceState: 'out',
    withinLocation: true,
    stats: Object.freeze({ workOrders: 6, pmTasks: 3, attendanceDays: 24, leaveDays: 8 }),
    tasks: Object.freeze([
      Object.freeze({ id: 'WO-2026-0745', title: 'Common Area Cleaning', location: 'Block A – Ground Floor', due: '10:00 AM', status: 'in_progress' }),
      Object.freeze({ id: 'PM-2026-0341', title: 'Water Pump Inspection', location: 'Pump Room – Level 1', due: '2:00 PM', status: 'pending' }),
      Object.freeze({ id: 'WO-2026-0751', title: 'Replace Corridor Light', location: 'Block C – Level 2', due: '4:30 PM', status: 'pending' }),
    ]),
  }),
  security: Object.freeze({
    shift: '7:00 PM – 7:00 AM',
    patrolState: 'idle',
    gpsActive: true,
    stats: Object.freeze({ checkpointsDone: 8, checkpointsTotal: 12, incidents: 1, visitors: 14 }),
    route: Object.freeze([1, 3, 7, 4, 5, 8, 12]),
    activities: Object.freeze([
      Object.freeze({ time: '9:15 PM', title: 'Checkpoint 8 scanned', status: 'completed' }),
      Object.freeze({ time: '8:42 PM', title: 'Visitor registered at Guard House', status: 'completed' }),
    ]),
  }),
});
