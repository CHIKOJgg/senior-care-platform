/**
 * Main Frontend Application Script
 */
document.addEventListener('DOMContentLoaded', () => {
    // Mode toggler
    const toggleSeniorBtn = document.getElementById('toggleSeniorMode');
    if (toggleSeniorBtn) {
        const isSenior = localStorage.getItem('seniorMode') === 'true';
        if (isSenior) document.body.classList.add('senior-mode');

        toggleSeniorBtn.addEventListener('click', () => {
            document.body.classList.toggle('senior-mode');
            const active = document.body.classList.contains('senior-mode');
            localStorage.setItem('seniorMode', active);
            SpeechHelper.speak(active ? 'Включён крупный режим для старшего поколения' : 'Включён стандартный режим');
        });
    }

    // Speak Page Button
    const speakPageBtn = document.getElementById('speakPageBtn');
    if (speakPageBtn) {
        speakPageBtn.addEventListener('click', () => {
            SpeechHelper.speakPage();
        });
    }

    // Tabs switching
    const tabBtns = document.querySelectorAll('.tab-btn');
    const tabPanes = document.querySelectorAll('.tab-pane');

    tabBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            tabBtns.forEach(b => b.classList.remove('active'));
            tabPanes.forEach(p => p.style.display = 'none');

            btn.classList.add('active');
            const targetId = btn.getAttribute('data-tab');
            const targetPane = document.getElementById(targetId);
            if (targetPane) targetPane.style.display = 'block';
        });
    });

    // Voice input button
    const micBtn = document.getElementById('micBtn');
    const requestDesc = document.getElementById('requestDescription');
    if (micBtn && requestDesc) {
        micBtn.addEventListener('click', () => {
            SpeechHelper.toggleListening(micBtn, (transcript) => {
                requestDesc.value = (requestDesc.value ? requestDesc.value + ' ' : '') + transcript;
                SpeechHelper.speak('Записано: ' + transcript);
            });
        });
    }

    // Category selection
    const catCards = document.querySelectorAll('.category-card');
    const selectedCatInput = document.getElementById('selectedCategory');
    catCards.forEach(card => {
        card.addEventListener('click', () => {
            catCards.forEach(c => c.classList.remove('selected'));
            card.classList.add('selected');
            if (selectedCatInput) {
                selectedCatInput.value = card.getAttribute('data-id');
            }
            const title = card.querySelector('.category-title')?.innerText || '';
            SpeechHelper.speak('Выбрана категория: ' + title);
        });
    });

    // Submit Request Form
    const helpForm = document.getElementById('helpForm');
    if (helpForm) {
        helpForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const catId = selectedCatInput ? selectedCatInput.value : 1;
            const desc = requestDesc ? requestDesc.value : '';
            const address = document.getElementById('requestAddress')?.value || 'Минск';

            if (!desc) {
                alert('Пожалуйста, укажите или надиктуйте, что вам нужно.');
                return;
            }

            try {
                const res = await fetch('/api/requests', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        elderly_id: 1,
                        category_id: catId,
                        title: desc.substring(0, 60),
                        description: desc,
                        address: address,
                        latitude: 53.9168,
                        longitude: 27.5862
                    })
                });
                const data = await res.json();
                if (data.success) {
                    showCreatedModal(data.secret_code);
                    helpForm.reset();
                }
            } catch (err) {
                console.error(err);
                alert('Ошибка при создании заявки');
            }
        });
    }

    // Load initial data
    loadVolunteerRequests();
    loadEducation();
});

function showCreatedModal(secretCode) {
    const banner = document.getElementById('secretCodeBanner');
    const wordEl = document.getElementById('displaySecretWord');
    if (banner && wordEl) {
        wordEl.innerText = secretCode;
        banner.style.display = 'block';
        banner.scrollIntoView({ behavior: 'smooth' });
        SpeechHelper.speak('Заявка создана! Ваше защитное кодовое слово: ' + secretCode + '. Волонтёр обязан назвать его у двери.');
    }
}

async function loadVolunteerRequests() {
    const list = document.getElementById('volunteerRequestList');
    if (!list) return;

    try {
        const res = await fetch('/api/requests?lat=53.9180&lon=27.5900');
        const json = await res.json();
        if (json.data) {
            list.innerHTML = json.data.map(req => `
                <div class="card" style="border-left: 6px solid #0284c7;">
                    <div style="display: flex; justify-content: space-between; align-items: start;">
                        <div>
                            <span style="font-size: 24px;">${req.category_icon || '📌'}</span>
                            <strong style="font-size: 20px;">${req.title}</strong>
                            <p style="margin: 8px 0; color: #475569;">${req.description}</p>
                            <p>📍 Адрес: <strong>${req.address}</strong> (расстояние: ~${req.distance_meters || 350} м)</p>
                            <p>👤 Подопечный: ${req.elderly_name} (${req.elderly_phone})</p>
                        </div>
                        <button class="btn btn-primary" onclick="acceptRequest(${req.id})">Взять заявку</button>
                    </div>
                </div>
            `).join('');
        }
    } catch (e) {
        console.error(e);
    }
}

async function acceptRequest(id) {
    try {
        const res = await fetch(`/api/requests/${id}/accept`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ volunteer_id: 2 })
        });
        const data = await res.json();
        if (data.success) {
            alert('Заявка принята! Секретный проверочный код: ' + data.secret_code);
            loadVolunteerRequests();
        }
    } catch (e) {
        alert('Не удалось принять заявку');
    }
}

async function loadEducation() {
    const container = document.getElementById('educationList');
    if (!container) return;

    try {
        const res = await fetch('/api/education');
        const json = await res.json();
        if (json.data) {
            container.innerHTML = json.data.map(item => `
                <div class="card">
                    <h3>🛡️ ${item.title}</h3>
                    <p style="margin: 10px 0;">${item.content}</p>
                    <button class="btn btn-outline" onclick="SpeechHelper.speak('${item.title}. ${item.content}')">🔊 Прослушать памятку</button>
                </div>
            `).join('');
        }
    } catch (e) {
        console.error(e);
    }
}
