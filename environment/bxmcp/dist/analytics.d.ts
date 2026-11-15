export declare function initAnalytics(): void;
export type AnalyticsEventType = "connection" | "tool_call" | "resource_read";
export declare function recordEvent(ev: {
    type: AnalyticsEventType;
    ip?: string | null;
    ua?: string | null;
    sessionId?: string | null;
    tool?: string | null;
    detail?: string | null;
}): void;
export interface PeriodStats {
    connections: number;
    uniqueIps: number;
    toolCalls: number;
    resourceReads: number;
}
export interface DailyStats extends PeriodStats {
    date: string;
}
export interface Stats {
    generatedAt: string;
    timezone: string;
    totals: PeriodStats;
    last24h: PeriodStats;
    today: DailyStats;
    online: {
        activeSessions: number;
        ipsLast15min: number;
    };
    daily: DailyStats[];
    topTools: Array<{
        tool: string;
        count: number;
    }>;
    topIps: Array<{
        ip: string | null;
        connections: number;
        lastSeen: number;
    }>;
    recentConnections: Array<{
        ts: number;
        ip: string | null;
        ua: string | null;
    }>;
}
export interface UsageToday {
    date: string;
    timezone: string;
    uses: number;
    users: number;
    toolCalls: number;
    resourceReads: number;
}
export declare function getTodayUsage(): UsageToday;
export declare function getStats(days?: number, activeSessions?: number): Stats;
