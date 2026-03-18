/**
 * NTDST Auth - Alpine.js Components
 */

/**
 * Login page component
 */
function authLogin() {
    return {
        email: '',
        password: '',
        loading: false,
        success: false,
        error: false,
        message: '',
        mode: window.ntdstAuth?.enablePassword ? 'password' : 'magic',

        async requestMagicLink() {
            this.loading = true;
            this.error = false;
            this.success = false;

            try {
                const response = await this.post('ntdst_auth_request_magic_link', {
                    email: this.email
                });

                if (response.success) {
                    this.success = true;
                    this.message = response.data?.message || '';
                } else {
                    this.error = true;
                    this.message = response.error || response.data?.message || 'An error occurred.';
                }
            } catch (e) {
                this.error = true;
                this.message = 'Network error. Please try again.';
            }

            this.loading = false;
        },

        loginPassword() {
            // Submit as traditional form POST — server handles auth + redirect.
            // This avoids stale cached pages from AJAX + JS redirect.
            this.loading = true;
            this.error = false;

            const form = document.createElement('form');
            form.method = 'POST';
            form.action = window.ntdstAuth?.ajaxUrl || '/wp/wp-admin/admin-ajax.php';

            const fields = {
                action: 'ntdst_auth_login_password',
                nonce: window.ntdstAuth?.nonce || '',
                email: this.email,
                password: this.password,
                _redirect: '1',
            };

            for (const [key, value] of Object.entries(fields)) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = key;
                input.value = value;
                form.appendChild(input);
            }

            document.body.appendChild(form);
            form.submit();
        },

        async post(action, data) {
            const formData = new FormData();
            formData.append('action', action);
            formData.append('nonce', window.ntdstAuth?.nonce || '');

            for (const [key, value] of Object.entries(data)) {
                formData.append(key, value);
            }

            const response = await fetch(window.ntdstAuth?.ajaxUrl || '/wp-admin/admin-ajax.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });

            return response.json();
        }
    };
}

/**
 * Registration page component
 */
function authRegister() {
    return {
        firstName: '',
        lastName: '',
        email: '',
        profileType: '',
        consentTerms: false,
        consentPrivacy: false,
        loading: false,
        success: false,
        error: false,
        message: '',

        async register() {
            this.loading = true;
            this.error = false;
            this.success = false;

            // Client-side validation
            if (this.profileType === '' && document.getElementById('profile_type')) {
                this.error = true;
                this.message = 'Kies een profieltype.';
                this.loading = false;
                return;
            }

            if (!this.consentTerms || !this.consentPrivacy) {
                this.error = true;
                this.message = 'Please accept the terms and privacy policy.';
                this.loading = false;
                return;
            }

            try {
                const response = await this.post('ntdst_auth_register', {
                    first_name: this.firstName,
                    last_name: this.lastName,
                    email: this.email,
                    profile_type: this.profileType,
                    consent_terms: this.consentTerms ? '1' : '',
                    consent_privacy: this.consentPrivacy ? '1' : ''
                });

                if (response.success) {
                    this.success = true;
                    this.message = response.data?.message || '';
                } else {
                    this.error = true;
                    this.message = response.error || response.data?.message || 'Registration failed.';
                }
            } catch (e) {
                this.error = true;
                this.message = 'Network error. Please try again.';
            }

            this.loading = false;
        },

        async post(action, data) {
            const formData = new FormData();
            formData.append('action', action);
            formData.append('nonce', window.ntdstAuth?.nonce || '');

            for (const [key, value] of Object.entries(data)) {
                formData.append(key, value);
            }

            const response = await fetch(window.ntdstAuth?.ajaxUrl || '/wp-admin/admin-ajax.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });

            return response.json();
        }
    };
}
