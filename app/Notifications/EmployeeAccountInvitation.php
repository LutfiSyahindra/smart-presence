<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmployeeAccountInvitation extends Notification
{
    use Queueable;

    public function __construct(public readonly string $token) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $expires = (int) config('auth.passwords.users.expire', 60);
        $url = route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
            'invitation' => 1,
        ]);

        return (new MailMessage)
            ->subject('Aktivasi Akun Pegawai RSARSY')
            ->greeting("Halo, {$notifiable->name}!")
            ->line('Anda telah didaftarkan sebagai pengguna aplikasi presensi RSARSY dengan role pegawai.')
            ->line('Klik tombol berikut untuk membuat password. Email Anda akan otomatis terverifikasi setelah password berhasil disimpan.')
            ->action('Aktifkan akun & buat password', $url)
            ->line("Tautan aktivasi ini berlaku selama {$expires} menit dan hanya dapat digunakan satu kali.")
            ->line('Jika Anda tidak merasa menerima undangan ini, abaikan email ini atau hubungi administrator.');
    }
}
