export interface AttendanceSubmissionStatus {
    id: string;
    action: 'clock-in' | 'clock-out';
    status: 'pending' | 'processing' | 'succeeded' | 'failed';
    received_at: string;
    date: string;
    time: string;
    outside_radius: boolean;
    face_similarity: number | null;
    message: string;
    status_url: string;
    api_status_url: string;
    success_url: string | null;
    retry_url: string;
}
