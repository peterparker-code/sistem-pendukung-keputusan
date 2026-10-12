'use strict';

/*
|--------------------------------------------------------------------------
| JavaScript Utama Sistem SPK PT CISAS
|--------------------------------------------------------------------------
|
| File ini menangani perilaku umum:
| - konfirmasi tindakan;
| - pencegahan submit ganda;
| - alert otomatis;
| - fokus setelah alert ditutup;
| - sidebar responsif;
| - checklist dan catatan temuan;
| - tampil/sembunyikan password;
| - tombol cetak.
|
*/

(() => {
    /*
    |--------------------------------------------------------------------------
    | Konstanta Aplikasi
    |--------------------------------------------------------------------------
    */

    const MOBILE_BREAKPOINT = 992;
    const ALERT_DISMISS_DELAY = 7000;

    /*
    |--------------------------------------------------------------------------
    | Menjalankan Fungsi Setelah DOM Siap
    |--------------------------------------------------------------------------
    */

    const onReady = (callback) => {
        if (document.readyState === 'loading') {
            document.addEventListener(
                'DOMContentLoaded',
                callback,
                {
                    once: true,
                }
            );

            return;
        }

        callback();
    };

    /*
    |--------------------------------------------------------------------------
    | Membaca Bootstrap Global
    |--------------------------------------------------------------------------
    */

    const bootstrapIsAvailable = () => {
        return typeof window.bootstrap !== 'undefined';
    };

    /*
    |--------------------------------------------------------------------------
    | Konfirmasi Form
    |--------------------------------------------------------------------------
    |
    | Contoh:
    |
    | <form
    |     method="post"
    |     data-confirm="Yakin ingin menghapus data ini?"
    | >
    |
    */

    const initializeConfirmForms = () => {
        const forms = document.querySelectorAll(
            'form[data-confirm]'
        );

        forms.forEach((form) => {
            form.addEventListener(
                'submit',
                (event) => {
                    const message = (
                        form.dataset.confirm || ''
                    ).trim();

                    if (message === '') {
                        return;
                    }

                    const isConfirmed = window.confirm(
                        message
                    );

                    if (!isConfirmed) {
                        event.preventDefault();
                        event.stopImmediatePropagation();
                    }
                },
                {
                    capture: true,
                }
            );
        });
    };

    /*
    |--------------------------------------------------------------------------
    | Menyimpan Nilai Tombol Submit
    |--------------------------------------------------------------------------
    |
    | Jika tombol mempunyai name dan value, salinan hidden input dibuat
    | sebelum tombol dinonaktifkan. Hal ini menjaga nilai aksi tetap
    | dikirim kepada server.
    |
    */

    const preserveSubmitterValue = (
        form,
        submitter
    ) => {
        if (!(submitter instanceof HTMLElement)) {
            return;
        }

        const submitterName = (
            submitter.getAttribute('name') || ''
        ).trim();

        if (submitterName === '') {
            return;
        }

        const hiddenInput =
            document.createElement('input');

        hiddenInput.type = 'hidden';
        hiddenInput.name = submitterName;
        hiddenInput.value =
            submitter.getAttribute('value') || '';

        hiddenInput.dataset.generatedSubmitter =
            'true';

        form.appendChild(hiddenInput);
    };

    /*
    |--------------------------------------------------------------------------
    | Mengubah Tombol Menjadi Status Memproses
    |--------------------------------------------------------------------------
    */

    const setSubmitButtonsLoading = (
        form,
        submitter
    ) => {
        const submitButtons = form.querySelectorAll(
            'button[type="submit"], input[type="submit"]'
        );

        submitButtons.forEach((button) => {
            button.dataset.originalDisabled =
                button.disabled
                    ? 'true'
                    : 'false';

            if (button instanceof HTMLButtonElement) {
                button.dataset.originalContent =
                    button.innerHTML;
            }

            if (button instanceof HTMLInputElement) {
                button.dataset.originalValue =
                    button.value;
            }

            button.disabled = true;
            button.setAttribute(
                'aria-disabled',
                'true'
            );
        });

        if (
            submitter instanceof HTMLButtonElement
        ) {
            const loadingText = (
                submitter.dataset.loadingText
                || 'Memproses...'
            ).trim();

            submitter.innerHTML = `
                <span
                    class="spinner-border spinner-border-sm me-2"
                    aria-hidden="true"
                ></span>
                <span>${escapeHtml(loadingText)}</span>
            `;
        }

        if (
            submitter instanceof HTMLInputElement
        ) {
            submitter.value = (
                submitter.dataset.loadingText
                || 'Memproses...'
            ).trim();
        }

        form.setAttribute(
            'aria-busy',
            'true'
        );
    };

    /*
    |--------------------------------------------------------------------------
    | Mengembalikan Tombol Form
    |--------------------------------------------------------------------------
    |
    | Digunakan ketika halaman dipulihkan dari browser back-forward cache.
    |
    */

    const resetFormSubmissionState = (form) => {
        form.dataset.submitting = 'false';

        form.removeAttribute(
            'aria-busy'
        );

        form.querySelectorAll(
            '[data-generated-submitter="true"]'
        ).forEach((input) => {
            input.remove();
        });

        const submitButtons = form.querySelectorAll(
            'button[type="submit"], input[type="submit"]'
        );

        submitButtons.forEach((button) => {
            const originallyDisabled =
                button.dataset.originalDisabled
                === 'true';

            button.disabled =
                originallyDisabled;

            button.removeAttribute(
                'aria-disabled'
            );

            if (
                button instanceof HTMLButtonElement
                && typeof button.dataset
                    .originalContent !== 'undefined'
            ) {
                button.innerHTML =
                    button.dataset.originalContent;
            }

            if (
                button instanceof HTMLInputElement
                && typeof button.dataset
                    .originalValue !== 'undefined'
            ) {
                button.value =
                    button.dataset.originalValue;
            }

            delete button.dataset.originalDisabled;
            delete button.dataset.originalContent;
            delete button.dataset.originalValue;
        });
    };

    /*
    |--------------------------------------------------------------------------
    | Mencegah Submit Ganda
    |--------------------------------------------------------------------------
    */

    const initializeDoubleSubmitProtection = () => {
        const forms = document.querySelectorAll(
            'form[method="post"], form[method="POST"]'
        );

        forms.forEach((form) => {
            form.dataset.submitting = 'false';

            form.addEventListener(
                'submit',
                (event) => {
                    if (
                        form.dataset.submitting
                        === 'true'
                    ) {
                        event.preventDefault();

                        return;
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Jangan Kunci Form yang Tidak Valid
                    |--------------------------------------------------------------------------
                    */

                    if (
                        typeof form.checkValidity
                            === 'function'
                        && !form.checkValidity()
                    ) {
                        return;
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | Form Dapat Mengecualikan Perlindungan
                    |--------------------------------------------------------------------------
                    */

                    if (
                        form.dataset.allowMultipleSubmit
                        === 'true'
                    ) {
                        return;
                    }

                    form.dataset.submitting = 'true';

                    const submitter =
                        event.submitter || null;

                    preserveSubmitterValue(
                        form,
                        submitter
                    );

                    setSubmitButtonsLoading(
                        form,
                        submitter
                    );
                }
            );
        });

        /*
        |--------------------------------------------------------------------------
        | Pulihkan Tombol Ketika Pengguna Menekan Tombol Back
        |--------------------------------------------------------------------------
        */

        window.addEventListener(
            'pageshow',
            () => {
                forms.forEach((form) => {
                    resetFormSubmissionState(
                        form
                    );
                });
            }
        );
    };

    /*
    |--------------------------------------------------------------------------
    | Escaping HTML untuk Teks JavaScript
    |--------------------------------------------------------------------------
    */

    const escapeHtml = (value) => {
        const temporaryElement =
            document.createElement('div');

        temporaryElement.textContent =
            String(value);

        return temporaryElement.innerHTML;
    };

    /*
    |--------------------------------------------------------------------------
    | Menutup Alert
    |--------------------------------------------------------------------------
    */

    const closeAlert = (alertElement) => {
        if (!(alertElement instanceof HTMLElement)) {
            return;
        }

        if (
            bootstrapIsAvailable()
            && window.bootstrap.Alert
        ) {
            const alertInstance =
                window.bootstrap.Alert
                    .getOrCreateInstance(
                        alertElement
                    );

            alertInstance.close();

            return;
        }

        alertElement.remove();
    };

    /*
    |--------------------------------------------------------------------------
    | Fokus Setelah Alert Ditutup
    |--------------------------------------------------------------------------
    |
    | Bootstrap menghapus alert dari DOM setelah ditutup. Jika alert ditutup
    | melalui keyboard, fokus dipindahkan ke konten utama agar tidak hilang.
    |
    */

    const initializeAlertFocusManagement = () => {
        const alerts = document.querySelectorAll(
            '.alert.alert-dismissible'
        );

        alerts.forEach((alertElement) => {
            const closeButton =
                alertElement.querySelector(
                    '[data-bs-dismiss="alert"]'
                );

            if (!(closeButton instanceof HTMLElement)) {
                return;
            }

            closeButton.addEventListener(
                'keydown',
                (event) => {
                    if (
                        event.key === 'Enter'
                        || event.key === ' '
                    ) {
                        alertElement.dataset
                            .restoreFocus = 'true';
                    }
                }
            );

            alertElement.addEventListener(
                'closed.bs.alert',
                () => {
                    if (
                        alertElement.dataset
                            .restoreFocus !== 'true'
                    ) {
                        return;
                    }

                    const mainContent =
                        document.getElementById(
                            'main-content'
                        );

                    if (
                        !(mainContent instanceof HTMLElement)
                    ) {
                        return;
                    }

                    if (
                        !mainContent.hasAttribute(
                            'tabindex'
                        )
                    ) {
                        mainContent.setAttribute(
                            'tabindex',
                            '-1'
                        );
                    }

                    mainContent.focus({
                        preventScroll: true,
                    });
                },
                {
                    once: true,
                }
            );
        });
    };

    /*
    |--------------------------------------------------------------------------
    | Alert Otomatis
    |--------------------------------------------------------------------------
    |
    | Pesan success dan info akan ditutup setelah beberapa detik.
    |
    | Tambahkan data-persist="true" pada alert jika pesan tidak boleh
    | ditutup otomatis.
    |
    */

    const initializeAutoDismissAlerts = () => {
        const alerts = document.querySelectorAll(
            '.alert.alert-success, .alert.alert-info'
        );

        alerts.forEach((alertElement) => {
            if (
                alertElement.dataset.persist
                === 'true'
            ) {
                return;
            }

            let timerId = null;

            const startTimer = () => {
                window.clearTimeout(
                    timerId
                );

                timerId = window.setTimeout(
                    () => {
                        closeAlert(
                            alertElement
                        );
                    },
                    ALERT_DISMISS_DELAY
                );
            };

            const stopTimer = () => {
                window.clearTimeout(
                    timerId
                );
            };

            alertElement.addEventListener(
                'mouseenter',
                stopTimer
            );

            alertElement.addEventListener(
                'mouseleave',
                startTimer
            );

            alertElement.addEventListener(
                'focusin',
                stopTimer
            );

            alertElement.addEventListener(
                'focusout',
                startTimer
            );

            startTimer();
        });
    };

    /*
    |--------------------------------------------------------------------------
    | Menutup Sidebar pada Layar Kecil
    |--------------------------------------------------------------------------
    */

    const initializeResponsiveSidebar = () => {
        const sidebar = document.getElementById(
            'mainSidebar'
        );

        if (!(sidebar instanceof HTMLElement)) {
            return;
        }

        const sidebarLinks = sidebar.querySelectorAll(
            'a.nav-link'
        );

        sidebarLinks.forEach((link) => {
            link.addEventListener(
                'click',
                () => {
                    if (
                        window.innerWidth
                        >= MOBILE_BREAKPOINT
                    ) {
                        return;
                    }

                    if (
                        !sidebar.classList.contains(
                            'show'
                        )
                    ) {
                        return;
                    }

                    if (
                        !bootstrapIsAvailable()
                        || !window.bootstrap.Offcanvas
                    ) {
                        return;
                    }

                    const offcanvas =
                        window.bootstrap.Offcanvas
                            .getOrCreateInstance(
                                sidebar
                            );

                    offcanvas.hide();
                }
            );
        });
    };

    /*
    |--------------------------------------------------------------------------
    | Kondisi Checklist
    |--------------------------------------------------------------------------
    |
    | Struktur yang didukung:
    |
    | <div data-checklist-item>
    |     <input
    |         type="radio"
    |         data-condition-input
    |         value="warning"
    |     >
    |
    |     <textarea data-finding-note></textarea>
    | </div>
    |
    | Keterangan wajib untuk:
    | - warning;
    | - danger.
    |
    */

    const updateChecklistItem = (
        checklistItem
    ) => {
        const selectedCondition =
            checklistItem.querySelector(
                'input[type="radio"]' +
                '[data-condition-input]:checked'
            );

        const findingNote =
            checklistItem.querySelector(
                '[data-finding-note]'
            );

        if (
            !(findingNote instanceof
                HTMLTextAreaElement)
            && !(findingNote instanceof
                HTMLInputElement)
        ) {
            return;
        }

        const condition =
            selectedCondition instanceof
                HTMLInputElement
                ? selectedCondition.value
                : '';

        const noteIsRequired = [
            'warning',
            'danger',
        ].includes(condition);

        findingNote.required =
            noteIsRequired;

        if (noteIsRequired) {
            findingNote.setAttribute(
                'aria-required',
                'true'
            );
        } else {
            findingNote.removeAttribute(
                'aria-required'
            );
        }

        checklistItem.dataset.conditionState =
            condition;

        /*
        |--------------------------------------------------------------------------
        | Mengubah Placeholder Sesuai Kondisi
        |--------------------------------------------------------------------------
        */

        if (condition === 'warning') {
            findingNote.placeholder =
                'Jelaskan gejala yang perlu diperhatikan.';
        } else if (condition === 'danger') {
            findingNote.placeholder =
                'Jelaskan kondisi Bahaya dan tindakan awal.';
        } else {
            findingNote.placeholder =
                'Tambahkan keterangan bila diperlukan.';
        }
    };

    const initializeChecklistBehavior = () => {
        const checklistItems =
            document.querySelectorAll(
                '[data-checklist-item]'
            );

        checklistItems.forEach(
            (checklistItem) => {
                const conditionInputs =
                    checklistItem.querySelectorAll(
                        'input[type="radio"]' +
                        '[data-condition-input]'
                    );

                conditionInputs.forEach(
                    (input) => {
                        input.addEventListener(
                            'change',
                            () => {
                                updateChecklistItem(
                                    checklistItem
                                );
                            }
                        );
                    }
                );

                updateChecklistItem(
                    checklistItem
                );
            }
        );
    };

    /*
    |--------------------------------------------------------------------------
    | Tampil atau Sembunyikan Password
    |--------------------------------------------------------------------------
    |
    | Contoh:
    |
    | <button
    |     type="button"
    |     data-password-toggle
    |     data-target="#password"
    | >
    |     Tampilkan
    | </button>
    |
    */

    const initializePasswordToggles = () => {
        const toggleButtons =
            document.querySelectorAll(
                '[data-password-toggle]'
            );

        toggleButtons.forEach((button) => {
            button.addEventListener(
                'click',
                () => {
                    const targetSelector = (
                        button.dataset.target || ''
                    ).trim();

                    if (targetSelector === '') {
                        return;
                    }

                    const passwordInput =
                        document.querySelector(
                            targetSelector
                        );

                    if (
                        !(passwordInput instanceof
                            HTMLInputElement)
                    ) {
                        return;
                    }

                    const passwordIsVisible =
                        passwordInput.type === 'text';

                    passwordInput.type =
                        passwordIsVisible
                            ? 'password'
                            : 'text';

                    button.setAttribute(
                        'aria-pressed',
                        passwordIsVisible
                            ? 'false'
                            : 'true'
                    );

                    const showLabel = (
                        button.dataset.showLabel
                        || 'Tampilkan'
                    ).trim();

                    const hideLabel = (
                        button.dataset.hideLabel
                        || 'Sembunyikan'
                    ).trim();

                    button.textContent =
                        passwordIsVisible
                            ? showLabel
                            : hideLabel;
                }
            );
        });
    };


    /*
    |--------------------------------------------------------------------------
    | Tombol Cetak
    |--------------------------------------------------------------------------
    |
    | Contoh:
    |
    | <button type="button" data-print>
    |     Cetak
    | </button>
    |
    */

    const initializePrintButtons = () => {
        const printButtons =
            document.querySelectorAll(
                '[data-print]'
            );

        printButtons.forEach((button) => {
            button.addEventListener(
                'click',
                () => {
                    window.print();
                }
            );
        });
    };

    /*
    |--------------------------------------------------------------------------
    | Menandai JavaScript Aktif
    |--------------------------------------------------------------------------
    */

    const markJavaScriptReady = () => {
        document.documentElement.classList.add(
            'js-enabled'
        );

        document.documentElement.dataset
            .appReady = 'true';
    };

    /*
    |--------------------------------------------------------------------------
    | Inisialisasi Aplikasi
    |--------------------------------------------------------------------------
    */

    onReady(() => {
        markJavaScriptReady();

        initializeConfirmForms();
        initializeDoubleSubmitProtection();

        initializeAlertFocusManagement();
        initializeAutoDismissAlerts();

        initializeResponsiveSidebar();
        initializeChecklistBehavior();

        initializePasswordToggles();
        initializePrintButtons();
    });
})();
