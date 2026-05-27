const loginForm = document.querySelector('.auth-form');
const loginInput = loginForm?.querySelector('input[name="login"]');
const rememberLoginInput = loginForm?.querySelector('input[name="remember_login"]');
const rememberedLoginKey = 'ocstudio.rememberedLogin';

if (loginInput && rememberLoginInput) {
    const storage = {
        get() {
            try {
                return localStorage.getItem(rememberedLoginKey);
            } catch (error) {
                return null;
            }
        },
        set(value) {
            try {
                localStorage.setItem(rememberedLoginKey, value);
            } catch (error) {
                return;
            }
        },
        remove() {
            try {
                localStorage.removeItem(rememberedLoginKey);
            } catch (error) {
                return;
            }
        }
    };

    const rememberedLogin = storage.get();

    if (rememberedLogin) {
        loginInput.value = rememberedLogin;
        rememberLoginInput.checked = true;
    }

    loginForm.addEventListener('submit', () => {
        const login = loginInput.value.trim();

        if (rememberLoginInput.checked && login !== '') {
            storage.set(login);
            return;
        }

        storage.remove();
    });
}
