<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthBase from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { LoaderCircle } from 'lucide-vue-next';

const props = defineProps<{
    status?: string;
    canResetPassword: boolean;
    loginUrl: string;
    portal: 'presensi' | 'admin';
}>();

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(props.loginUrl, {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <AuthBase
        :title="portal === 'admin' ? 'Masuk ke portal admin' : 'Masuk ke aplikasi presensi'"
        :description="
            portal === 'admin' ? 'Gunakan akun dengan akses administrator untuk melanjutkan.' : 'Gunakan akun pegawai RSARSY untuk melanjutkan.'
        "
    >
        <Head :title="portal === 'admin' ? 'Login Admin' : 'Login Presensi'" />

        <div v-if="status" class="mb-4 text-center text-sm font-medium text-green-600">
            {{ status }}
        </div>

        <form @submit.prevent="submit" class="flex flex-col gap-6">
            <div class="grid gap-6">
                <div class="grid gap-2">
                    <Label for="email">Alamat email</Label>
                    <Input
                        id="email"
                        type="email"
                        required
                        autofocus
                        tabindex="1"
                        autocomplete="email"
                        v-model="form.email"
                        placeholder="email@example.com"
                    />
                    <InputError :message="form.errors.email" />
                </div>

                <div class="grid gap-2">
                    <div class="flex items-center justify-between">
                        <Label for="password">Password</Label>
                        <TextLink v-if="canResetPassword" :href="route('password.request')" class="text-sm" tabindex="5"> Lupa kata sandi? </TextLink>
                    </div>
                    <Input
                        id="password"
                        type="password"
                        required
                        tabindex="2"
                        autocomplete="current-password"
                        v-model="form.password"
                        placeholder="Kata sandi"
                    />
                    <InputError :message="form.errors.password" />
                </div>

                <div class="flex items-center justify-between" tabindex="3">
                    <Label for="remember" class="flex items-center space-x-3">
                        <Checkbox id="remember" v-model:checked="form.remember" tabindex="4" />
                        <span>Ingat saya</span>
                    </Label>
                </div>

                <Button type="submit" class="mt-4 w-full" tabindex="4" :disabled="form.processing">
                    <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                    {{ portal === 'admin' ? 'Masuk ke portal admin' : 'Masuk ke presensi' }}
                </Button>
            </div>

            <div v-if="portal === 'admin'" class="text-center text-sm text-muted-foreground">
                Ingin melakukan presensi?
                <TextLink :href="route('login')">Buka aplikasi presensi</TextLink>
            </div>

            <div v-if="portal !== 'admin'" class="text-center text-sm text-muted-foreground">
                Belum memiliki akun?
                <TextLink :href="route('register')" :tabindex="5">Daftar sekarang</TextLink>
            </div>
        </form>
    </AuthBase>
</template>
