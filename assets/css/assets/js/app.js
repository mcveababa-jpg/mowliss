document.addEventListener("DOMContentLoaded", function () {
    const tabs = document.querySelectorAll(".tab-btn");

    const loginPanel = document.getElementById("login-panel");
    const registerPanel = document.getElementById("register-panel");

    const loginForm = document.getElementById("login-form");
    const registerForm = document.getElementById("register-form");

    const loginStatus = document.getElementById("login_status");
    const loginIdentifierLabel = document.getElementById("login_identifier_label");
    const loginIdentifier = document.getElementById("login_identifier");
    const loginPassword = document.getElementById("login_password");
    const rememberMe = document.getElementById("remember_me");

    const registerStatus = document.getElementById("register_status");
    const registerPassword = document.getElementById("register_password");
    const registerConfirmPassword = document.getElementById("register_confirm_password");
    const termsAgreed = document.getElementById("terms_agreed");

    const loginMessage = document.getElementById("login-message");
    const registerMessage = document.getElementById("register-message");

    const loginButton = loginForm ? loginForm.querySelector("button[type=submit]") : null;
    const registerButton = registerForm ? registerForm.querySelector("button[type=submit]") : null;

    if (!loginForm || !registerForm) {
        console.error("MoWLiSS Error: login form or register form not found.");
        return;
    }

    /*
        Disable native HTML validation.
        We will validate using JavaScript so hidden dynamic fields
        do not silently block the form.
    */
    loginForm.setAttribute("novalidate", "novalidate");
    registerForm.setAttribute("novalidate", "novalidate");

    const identifierLabels = {
        student: "Student ID No.",
        staff: "Worker Reg No.",
        foreman: "Foreman Reg No.",
        admin: "Admin ID"
    };

    function showMessage(element, type, text) {
        if (!element) return;

        element.className = "message message-" + type;
        element.textContent = text;
        element.classList.remove("hidden");
    }

    function clearMessage(element) {
        if (!element) return;

        element.className = "message hidden";
        element.textContent = "";
    }

    function switchTab(tabName) {
        tabs.forEach(function (button) {
            button.classList.toggle("active", button.dataset.tab === tabName);
        });

        if (!loginPanel || !registerPanel) return;

        if (tabName === "login") {
            loginPanel.classList.remove("hidden");
            registerPanel.classList.add("hidden");
        } else {
            loginPanel.classList.add("hidden");
            registerPanel.classList.remove("hidden");
        }
    }

    function updateLoginIdentifierLabel() {
        if (!loginStatus || !loginIdentifierLabel || !loginIdentifier) return;

        const status = loginStatus.value;

        if (identifierLabels[status]) {
            loginIdentifierLabel.textContent = identifierLabels[status] + " *";
            loginIdentifier.placeholder = "Enter your " + identifierLabels[status];
        } else {
            loginIdentifierLabel.textContent = "ID Number *";
            loginIdentifier.placeholder = "Enter your ID number";
        }
    }

    function setControlsEnabled(container, enabled) {
        if (!container) return;

        const controls = container.querySelectorAll("input, select, textarea");

        controls.forEach(function (control) {
            control.disabled = !enabled;
        });
    }

    function activateRegisterFields() {
        if (!registerForm || !registerStatus) return;

        const selectedStatus = registerStatus.value;
        const sections = registerForm.querySelectorAll(".status-fields");

        sections.forEach(function (section) {
            const isActive = section.id === selectedStatus + "-fields";

            section.classList.toggle("active", isActive);
            setControlsEnabled(section, isActive);
        });
    }

    function setLoading(button, loading) {
        if (!button) return;

        if (loading) {
            button.dataset.originalText = button.textContent;
            button.disabled = true;
            button.textContent = "Please wait...";
        } else {
            button.disabled = false;
            button.textContent = button.dataset.originalText || "Submit";
        }
    }

    async function postForm(url, formData) {
        try {
            const response = await fetch(url, {
                method: "POST",
                body: formData,
                credentials: "same-origin",
                headers: {
                    "X-Requested-With": "XMLHttpRequest"
                }
            });

            const text = await response.text();

            try {
                return JSON.parse(text);
            } catch (parseError) {
                console.error("Non-JSON response from " + url + ":", text);
                return {
                    success: false,
                    message: "Server returned an invalid response. Check PHP error logs."
                };
            }
        } catch (networkError) {
            console.error("Fetch error:", networkError);
            return {
                success: false,
                message: "Cannot connect to " + url + ". Make sure Apache is running and you are using http://localhost."
            };
        }
    }

    function validateLogin() {
        if (!loginStatus.value) {
            return "Please select your user status.";
        }

        if (!loginIdentifier.value.trim()) {
            return "Please enter your ID/registration number.";
        }

        if (!loginPassword.value) {
            return "Please enter your password.";
        }

        return null;
    }

    function validateRegister() {
        if (!registerStatus.value) {
            return "Please select your user status.";
        }

        const activeSection = registerForm.querySelector(".status-fields.active");

        if (activeSection) {
            const requiredFields = activeSection.querySelectorAll("[required]");

            for (const field of requiredFields) {
                if (field.disabled) {
                    continue;
                }

                if (!field.value.trim()) {
                    const formGroup = field.closest(".form-group");
                    const label = formGroup ? formGroup.querySelector("label") : null;
                    const fieldName = label ? label.textContent.replace("*", "").trim() : "This field";

                    return fieldName + " is required.";
                }
            }
        }

        if (!registerPassword.value) {
            return "Password is required.";
        }

        if (registerPassword.value.length < 8) {
            return "Password must be at least 8 characters long.";
        }

        if (registerPassword.value !== registerConfirmPassword.value) {
            return "Passwords do not match.";
        }

        if (!termsAgreed.checked) {
            return "You must agree to the Terms & Conditions and MoWLiSS security policy.";
        }

        return null;
    }

    function getLoginFormData() {
        const formData = new FormData();

        formData.append("user_status", loginStatus.value);
        formData.append("identifier", loginIdentifier.value.trim());
        formData.append("password", loginPassword.value);

        if (rememberMe && rememberMe.checked) {
            formData.append("remember_me", "1");
        }

        return formData;
    }

    function getRegisterFormData() {
        const formData = new FormData();

        formData.append("user_status", registerStatus.value);

        /*
            Only send fields from the active status section.
            This prevents duplicate field names like department,
            phone_number, first_name, etc. from being submitted together.
        */
        const activeSection = registerForm.querySelector(".status-fields.active");

        if (activeSection) {
            const controls = activeSection.querySelectorAll("input, select, textarea");

            controls.forEach(function (control) {
                if (!control.disabled && control.name) {
                    formData.append(control.name, control.value);
                }
            });
        }

        formData.append("password", registerPassword.value);
        formData.append("confirm_password", registerConfirmPassword.value);

        if (termsAgreed.checked) {
            formData.append("terms_agreed", "1");
        }

        return formData;
    }

    tabs.forEach(function (button) {
        button.addEventListener("click", function () {
            switchTab(button.dataset.tab);
        });
    });

    if (loginStatus) {
        loginStatus.addEventListener("change", updateLoginIdentifierLabel);
        updateLoginIdentifierLabel();
    }

    if (registerStatus) {
        registerStatus.addEventListener("change", activateRegisterFields);
        activateRegisterFields();
    }

    loginForm.addEventListener("submit", async function (event) {
        event.preventDefault();

        clearMessage(loginMessage);

        const validationError = validateLogin();

        if (validationError) {
            showMessage(loginMessage, "error", validationError);
            return;
        }

        setLoading(loginButton, true);

        try {
            const formData = getLoginFormData();
            const result = await postForm("login.php", formData);

            if (result.success) {
                showMessage(loginMessage, "success", result.message || "Login successful.");

                if (result.redirect) {
                    window.location.href = result.redirect;
                }
            } else {
                showMessage(loginMessage, "error", result.message || "Login failed.");
            }
        } catch (error) {
            console.error(error);
            showMessage(loginMessage, "error", "Unexpected login error.");
        } finally {
            setLoading(loginButton, false);
        }
    });

    registerForm.addEventListener("submit", async function (event) {
        event.preventDefault();

        clearMessage(registerMessage);

        const validationError = validateRegister();

        if (validationError) {
            showMessage(registerMessage, "error", validationError);
            return;
        }

        /*
            Make sure the correct dynamic fields are active
            before collecting form data.
        */
        activateRegisterFields();

        setLoading(registerButton, true);

        try {
            const formData = getRegisterFormData();
            const result = await postForm("register.php", formData);

            if (result.success) {
                registerForm.reset();
                activateRegisterFields();

                switchTab("login");
                showMessage(loginMessage, "success", result.message || "Registration successful. Please login.");
            } else {
                showMessage(registerMessage, "error", result.message || "Registration failed.");
            }
        } catch (error) {
            console.error(error);
            showMessage(registerMessage, "error", "Unexpected registration error.");
        } finally {
            setLoading(registerButton, false);
        }
    });

    /*
        Extra safety:
        If the register button was accidentally changed to type="button",
        this will still trigger the form submission logic.
    */
    if (registerButton && registerButton.type !== "submit") {
        registerButton.addEventListener("click", function () {
            if (typeof registerForm.requestSubmit === "function") {
                registerForm.requestSubmit();
            } else {
                registerForm.dispatchEvent(new Event("submit", { cancelable: true }));
            }
        });
    }

    const urlParams = new URLSearchParams(window.location.search);

    if (urlParams.get("tab") === "register") {
        switchTab("register");
    }
});