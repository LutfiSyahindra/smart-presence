export interface ApiEnvelope<T> {
    data: T;
    message?: string;
}

export interface UserSummary {
    name: string;
    email: string;
    can_access_account: boolean;
    has_profile_photo: boolean;
}

export interface AttendanceEntry {
    time: string;
    date_time_label: string;
    address: string | null;
    face_verified: boolean;
    face_similarity: number | null;
    late_minutes?: number;
    early_departure_minutes?: number;
}

export type AttendanceStatus = 'on_leave' | 'day_off' | 'complete' | 'unavailable' | 'ready_to_clock_in' | 'ready_to_clock_out';

export interface Attendance {
    id: number | null;
    date: string;
    date_label: string;
    status: AttendanceStatus;
    is_day_off: boolean;
    is_on_leave: boolean;
    duration: string | null;
    notes: string | null;
    shift: {
        name: string | null;
        start_time: string | null;
        end_time: string | null;
    };
    office: {
        name: string | null;
        address: string | null;
    };
    clock_in: AttendanceEntry | null;
    clock_out: AttendanceEntry | null;
}

export interface HomeData {
    attendance_submissions?: AttendanceSubmissionStatus[];
    user: UserSummary;
    date: { value: string; label: string };
    schedule: {
        shift_name: string | null;
        start_time: string | null;
        end_time: string | null;
        location_name: string | null;
    };
    attendance: Attendance;
}

export interface LocationChallenge {
    token: string;
    min_samples: number;
    collection_seconds: number;
    max_accuracy_meters: number;
    max_speed_meters_per_second: number;
}

export interface AttendanceActionData {
    action: 'clock-in' | 'clock-out';
    schedule: {
        date: string;
        date_label: string;
        shift_name: string | null;
        start_time: string | null;
        end_time: string | null;
    };
    office: {
        id: number;
        name: string;
        address: string | null;
        latitude: number;
        longitude: number;
        radius_meters: number;
    };
    location_challenge: LocationChallenge;
}

export interface AttendanceSubmissionStatus {
    id: string;
    action: 'clock-in' | 'clock-out';
    status: 'pending' | 'processing' | 'succeeded' | 'failed';
    received_at: string;
    date: string;
    time: string;
    message: string;
    face_similarity: number | null;
}

export interface LocationSample {
    latitude: number;
    longitude: number;
    accuracy: number;
    captured_at: number;
}

export interface HistoryItem {
    id: number;
    date: string;
    date_label: string;
    shift_name: string | null;
    clock_in: string | null;
    clock_out: string | null;
    is_day_off: boolean;
    late_minutes: number;
    early_departure_minutes: number;
}

export interface AccountData {
    name: string;
    email: string;
    nik: string | null;
    profession: string | null;
    position: string | null;
    unit: string | null;
    phone: string | null;
    has_employee_profile: boolean;
    has_photo: boolean;
    photo_updated_at: string | null;
}
