/* Universal Review Funnel - Frontend Script */

document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('wprf-funnel-form');
    if (!form) return;

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const responseBox = document.getElementById('wprf-ajax-response');
        const ratingInput = form.querySelector('input[name="wprf_rating"]:checked');
        
        if (!ratingInput) {
            responseBox.style.display = 'block';
            responseBox.style.background = '#fed7d7';
            responseBox.style.color = '#c53030';
            responseBox.innerText = wprf_frontend_vars.t_js_star_alert || 'Please select a star rating.';
            return;
        }

        const reviewText = form.querySelector('#wprf_text').value;
        const emailField = form.querySelector('#wprf_author_email');
        const authorEmail = emailField ? emailField.value : '';

        // GDPR verification if the checkbox exists
        const gdprConsent = form.querySelector('#wprf_gdpr_consent');
        if (gdprConsent && !gdprConsent.checked) {
            responseBox.style.display = 'block';
            responseBox.style.background = '#fed7d7';
            responseBox.style.color = '#c53030';
            responseBox.innerText = wprf_frontend_vars.t_gdpr_error || 'Please accept the privacy policy to continue.';
            return;
        }

        // Honeypot anti-spam verification
        const honeypotField = form.querySelector('input[name="wprf_verify_phone"]');
        const honeypotVal = honeypotField ? honeypotField.value : '';

        const formData = new FormData();
        formData.append('action', 'wprf_submit_funnel');
        formData.append('nonce', form.querySelector('#wprf_nonce').value);
        formData.append('profile_id', form.querySelector('input[name="profile_id"]').value);
        formData.append('rating', ratingInput.value);
        formData.append('author', form.querySelector('#wprf_author').value);
        formData.append('author_email', authorEmail);
        formData.append('text', reviewText);
        formData.append('wprf_verify_phone', honeypotVal);

        // Append Cloudflare Turnstile token if widget exists
        const turnstileResponse = form.querySelector('[name="cf-turnstile-response"]');
        if (turnstileResponse) {
            formData.append('cf-turnstile-response', turnstileResponse.value);
        }

        // GDPR marketing check (newsletter consent)
        const marketingConsent = form.querySelector('#wprf_marketing_consent');
        if (marketingConsent) {
            formData.append('marketing_consent', marketingConsent.checked ? '1' : '0');
        }

        responseBox.style.display = 'block';
        responseBox.style.background = '#e2e8f0';
        responseBox.style.color = '#4a5568';
        responseBox.innerText = wprf_frontend_vars.t_js_processing || 'Processing your feedback...';

        fetch(wprf_frontend_vars.ajax_url, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(res => {
            if (!res.success) {
                responseBox.style.background = '#fed7d7';
                responseBox.style.color = '#c53030';
                responseBox.innerText = res.data.message;
                return;
            }

            form.reset();
            
            if (res.data.action === 'google_redirect') {
                responseBox.style.background = '#c6f6d5';
                responseBox.style.color = '#22543d';
                responseBox.innerHTML = `
                    <div style="text-align: center; padding: 5px 0;">
                        <p style="margin: 0 0 10px 0; font-size: 14px; font-weight: bold; color: #1c5233;">${res.data.message}</p>
                        <p style="font-size: 12px; margin: 0 0 15px 0; color: #2f855a; font-style: italic;">${wprf_frontend_vars.t_clipboard_msg}</p>
                        <div class="wprf-button-group">
                            <button type="button" id="wprf-copy-btn" class="wprf-copy-btn" style="background-color: ${wprf_frontend_vars.btn_color}; color: ${wprf_frontend_vars.text_color};">
                                ${wprf_frontend_vars.t_copy_btn}
                            </button>
                            <a href="${res.data.google_url}" target="_blank" rel="noopener noreferrer" class="wprf-google-btn">
                                ${wprf_frontend_vars.t_google_btn}
                            </a>
                        </div>
                    </div>
                `;
                
                const copyBtn = document.getElementById('wprf-copy-btn');
                if (copyBtn) {
                    copyBtn.addEventListener('click', function() {
                        const textToCopy = res.data.text || reviewText;
                        if (navigator.clipboard && navigator.clipboard.writeText) {
                            navigator.clipboard.writeText(textToCopy).then(() => {
                                showCopiedState(copyBtn);
                            }).catch(err => {
                                fallbackCopyText(textToCopy);
                                showCopiedState(copyBtn);
                            });
                        } else {
                            fallbackCopyText(textToCopy);
                            showCopiedState(copyBtn);
                        }
                    });
                }

                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(reviewText).catch(err => {
                        fallbackCopyText(reviewText);
                    });
                } else {
                    fallbackCopyText(reviewText);
                }

            } else {
                responseBox.style.background = '#e6fffa';
                responseBox.style.color = '#234e52';
                responseBox.innerText = res.data.message;
                if(res.data.debug_place_id === 'missing_or_empty') {
                    console.warn('WPRF Warning: Google Place ID is missing in plugin options! Fix option key name to wprf_google_place_id.');
                }
            }

            // Smooth scroll to container bottom so response messages and redirect buttons are fully in view
            setTimeout(() => {
                const container = document.getElementById('wprf-funnel-box');
                if (container) {
                    container.scrollIntoView({ behavior: 'smooth', block: 'end' });
                }
            }, 150);
        })
        .catch(error => {
            responseBox.style.background = '#fed7d7';
            responseBox.style.color = '#c53030';
            responseBox.innerText = wprf_frontend_vars.t_js_error || 'An error occurred. Please try again.';
        });
    });

    function fallbackCopyText(text) {
        const textArea = document.createElement("textarea");
        textArea.value = text;
        textArea.style.position = "fixed"; 
        textArea.style.left = "-999999px";
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        try { document.execCommand('copy'); } catch (err) {}
        document.body.removeChild(textArea);
    }

    function showCopiedState(btn) {
        const originalText = btn.innerText;
        btn.innerText = wprf_frontend_vars.t_js_copied || 'Copied!';
        btn.classList.add('copied');
        setTimeout(function() {
            btn.innerText = originalText;
            btn.classList.remove('copied');
        }, 2000);
    }

    // Toggle newsletter consent visibility based on email field value
    const emailInput = document.getElementById('wprf_author_email');
    const newsletterWrapper = document.getElementById('wprf-newsletter-consent-wrapper');
    if (emailInput && newsletterWrapper) {
        const toggleNewsletter = () => {
            if (emailInput.value.trim().length > 0) {
                newsletterWrapper.style.display = 'flex';
            } else {
                newsletterWrapper.style.display = 'none';
                const checkbox = document.getElementById('wprf_marketing_consent');
                if (checkbox) checkbox.checked = false;
            }
        };
        emailInput.addEventListener('input', toggleNewsletter);
        toggleNewsletter();
    }
});

/* Slider initialization logic */
document.addEventListener('DOMContentLoaded', function() {
    const sliders = document.querySelectorAll('.wprf-slider-container');
    sliders.forEach(slider => {
        const track = slider.querySelector('.wprf-slider-track');
        const slides = slider.querySelectorAll('.wprf-slider-slide');
        const prevBtn = slider.querySelector('.wprf-slider-nav.prev');
        const nextBtn = slider.querySelector('.wprf-slider-nav.next');
        const dotsContainer = slider.querySelector('.wprf-slider-dots');

        const autoplayTime = parseInt(slider.getAttribute('data-autoplay')) || 0;
        const showArrows = slider.getAttribute('data-arrows') === 'true';
        const showDots = slider.getAttribute('data-dots') === 'true';
        const dotColor = wprf_frontend_vars.slider_dot_color || '#007a78';
        
        let currentIndex = 0;
        let autoplayInterval = null;

        function getItemsPerPage() {
            const width = window.innerWidth;
            if (width <= 600) return 1;
            if (width <= 980) {
                return Math.min(2, slides.length);
            }
            if (slider.classList.contains('wprf-slider-cols-4')) return Math.min(4, slides.length);
            if (slider.classList.contains('wprf-slider-cols-3')) return Math.min(3, slides.length);
            if (slider.classList.contains('wprf-slider-cols-2')) return Math.min(2, slides.length);
            return 1;
        }

        function getMaxIndex() {
            const itemsPerPage = getItemsPerPage();
            return Math.max(0, slides.length - itemsPerPage);
        }

        function updateSlider() {
            const itemsPerPage = getItemsPerPage();
            const maxIndex = getMaxIndex();
            if (currentIndex > maxIndex) {
                currentIndex = maxIndex;
            }

            const gap = 20;
            let offset = 0;
            if (slides.length > 0) {
                const containerViewport = slider.querySelector('.wprf-slider-viewport');
                if (containerViewport) {
                    const containerWidth = containerViewport.clientWidth;
                    const slideWidth = (containerWidth - (gap * (itemsPerPage - 1))) / itemsPerPage;
                    offset = currentIndex * (slideWidth + gap);
                }
            }
            
            if (track) {
                track.style.transform = `translateX(-${offset}px)`;
            }

            const dots = slider.querySelectorAll('.wprf-slider-dot');
            dots.forEach((dot, idx) => {
                if (idx === currentIndex) {
                    dot.classList.add('active');
                } else {
                    dot.classList.remove('active');
                }
            });

            if (prevBtn) prevBtn.disabled = currentIndex === 0;
            if (nextBtn) nextBtn.disabled = currentIndex === maxIndex;
        }

        function buildDots() {
            if (!dotsContainer || !showDots) return;
            dotsContainer.innerHTML = '';
            const maxIndex = getMaxIndex();
            for (let i = 0; i <= maxIndex; i++) {
                const dot = document.createElement('span');
                dot.classList.add('wprf-slider-dot');
                dot.style.backgroundColor = dotColor;
                if (i === currentIndex) dot.classList.add('active');
                dot.addEventListener('click', () => {
                    currentIndex = i;
                    updateSlider();
                    resetAutoplay();
                });
                dotsContainer.appendChild(dot);
            }
        }

        if (showDots) {
            buildDots();
        }

        if (prevBtn) {
            prevBtn.addEventListener('click', () => {
                if (currentIndex > 0) {
                    currentIndex--;
                    updateSlider();
                    resetAutoplay();
                }
            });
        }

        if (nextBtn) {
            nextBtn.addEventListener('click', () => {
                const maxIndex = getMaxIndex();
                if (currentIndex < maxIndex) {
                    currentIndex++;
                    updateSlider();
                    resetAutoplay();
                }
            });
        }

        function startAutoplay() {
            if (autoplayTime > 0) {
                autoplayInterval = setInterval(() => {
                    const maxIndex = getMaxIndex();
                    if (currentIndex >= maxIndex) {
                        currentIndex = 0;
                    } else {
                        currentIndex++;
                    }
                    updateSlider();
                }, autoplayTime);
            }
        }

        function stopAutoplay() {
            if (autoplayInterval) {
                clearInterval(autoplayInterval);
            }
        }

        function resetAutoplay() {
            stopAutoplay();
            startAutoplay();
        }

        slider.addEventListener('mouseenter', stopAutoplay);
        slider.addEventListener('mouseleave', startAutoplay);

        updateSlider();
        startAutoplay();

        window.addEventListener('resize', () => {
            buildDots();
            updateSlider();
        });
    });
});

// Toggle Read More / Read Less behavior for long review texts
document.addEventListener('click', function(e) {
    if (e.target && e.target.classList.contains('wprf-readmore-toggle')) {
        const card = e.target.closest('.wprf-review-card');
        if (card) {
            const moreText = card.querySelector('.wprf-text-more');
            if (moreText) {
                if (moreText.style.display === 'none') {
                    moreText.style.display = 'inline';
                    e.target.innerText = wprf_frontend_vars.t_read_less || 'read less';
                } else {
                    moreText.style.display = 'none';
                    e.target.innerText = wprf_frontend_vars.t_read_more || 'read more';
                }
                
                // If this is inside a slider, trigger window resize event to recalculate slide heights/positions
                const slider = card.closest('.wprf-slider-container');
                if (slider) {
                    window.dispatchEvent(new Event('resize'));
                }
            }
        }
    }
});
