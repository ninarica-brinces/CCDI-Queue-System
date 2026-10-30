let selectedServiceText = "Tuition Payment";

// Event listener initialization once the DOM fully loads
document.addEventListener("DOMContentLoaded", () => {
    // Service selector handling
    const serviceButtons = document.querySelectorAll('.service-btn');
    serviceButtons.forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            serviceButtons.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            selectedServiceText = btn.getAttribute('data-service') || btn.innerText;
        });
    });

    // Priority lane listener handling
    const laneRadios = document.querySelectorAll('input[name="lane"]');
    laneRadios.forEach(radio => {
        radio.addEventListener('change', toggleRelationField);
    });

    // Attach submit listener to the "Join Queue" button
    const joinBtn = document.getElementById("joinQueueBtn");
    if (joinBtn) {
        joinBtn.textContent = "Join Queue"; // Sets initial button label
        joinBtn.addEventListener("click", joinQueue);
    }

    // Run initial state check
    toggleRelationField();
});

// Toggles relation dropdown field based on lane selection
function toggleRelationField() {
    const selectedLane = document.querySelector('input[name="lane"]:checked');
    const relationBox = document.getElementById("relationContainer");
    
    if (!relationBox) return;

    if (selectedLane && selectedLane.value === "Parent") {
        relationBox.style.display = "block";
    } else {
        relationBox.style.display = "none";
    }
}

// Main Queue Generation & Form Processing Logic
async function joinQueue() {
    // -------------------------------------------------------------
    // 1. FETCH & VALIDATE FORM INPUTS
    // -------------------------------------------------------------
    const nameInput = document.getElementById("studentName");
    const addressInput = document.getElementById("studentAddress");
    const courseInput = document.getElementById("studentCourse");
    const yearInput = document.getElementById("studentYear");
    const parentRelationInput = document.getElementById("parentRelation"); // HTML element should be a <select> element

    const name = nameInput ? nameInput.value.trim() : "";
    const address = addressInput ? addressInput.value.trim() : "";
    const course = courseInput ? courseInput.value : "";
    const year = yearInput ? yearInput.value : "";
    const relation = parentRelationInput ? parentRelationInput.value : "";

    const selectedLaneElem = document.querySelector('input[name="lane"]:checked');
    const laneValue = selectedLaneElem ? selectedLaneElem.value : "Regular";

    if (!name) {
        alert("Please enter your full name.");
        if (nameInput) nameInput.focus();
        return;
    }
    if (!address) {
        alert("Please enter your address.");
        if (addressInput) addressInput.focus();
        return;
    }
    if (!course) {
        alert("Please select your course.");
        if (courseInput) courseInput.focus();
        return;
    }
    if (!year) {
        alert("Please select your year level.");
        if (yearInput) yearInput.focus();
        return;
    }
    if (laneValue === "Parent" && !relation) {
        alert("Please select your relation (Mother, Father, or Guardian).");
        if (parentRelationInput) parentRelationInput.focus();
        return;
    }

    // Safely fetch radio selections
    const periodElem = document.querySelector('input[name="period"]:checked');
    const typeElem = document.querySelector('input[name="type"]:checked');

    const period = periodElem ? periodElem.value : "Prelim";
    const type = typeElem ? typeElem.value : "Partial Payment";
    const joinButton = document.getElementById("joinQueueBtn");
    const monitorWindow = window.open("", "_blank");
    if (joinButton) joinButton.disabled = true;

    try {
        const studentId = document.getElementById("studentId")?.value.trim()
            || document.getElementById("studIdDisplay")?.value.trim()
            || "";
        const response = await fetch("./api.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                action: "create_ticket",
                lane: laneValue.toLowerCase().includes("pwd") || laneValue.toLowerCase().includes("priority")
                    ? "Priority"
                    : "Regular",
                studentId,
                studentName: name,
                course,
                address,
                yearLevel: year,
                service: selectedServiceText,
                payment: type,
                paymentPeriod: period,
                notes: relation
            })
        });
        const result = await response.json();
        if (!response.ok || !result.success) {
            throw new Error(result.message || "Unable to join the queue.");
        }

        const queueNumber = result.ticket_number;
        const ticketId = Number(result.ticket_id);
        if (!queueNumber || !Number.isSafeInteger(ticketId) || ticketId < 1) {
            throw new Error("The queue service returned an invalid ticket.");
        }
        const ticketData = {
            ticketId,
            queueNumber,
            name,
            courseYear: `${course} (${year})`,
            service: selectedServiceText,
            details: `${period} (${type})`,
            lane: laneValue,
            relation: laneValue === "Parent" ? relation : "",
            timestamp: new Date().toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" })
        };
        if (monitorWindow) openMonitoringTab(ticketData, monitorWindow);

        const queueNumberDisplay = document.getElementById("queueNumber");
        const queueResultBox = document.getElementById("queueResult");

        if (queueNumberDisplay) queueNumberDisplay.textContent = queueNumber;
        if (queueResultBox) queueResultBox.style.display = "block";

        const rName = document.getElementById("rName");
        const rCourse = document.getElementById("rCourse");
        const rService = document.getElementById("rService");
        const rDetails = document.getElementById("rDetails");
        const rQueueNum = document.getElementById("rQueueNum");

        if (rName) rName.textContent = name;
        if (rCourse) rCourse.textContent = `${course} (${year})`;
        if (rService) rService.textContent = selectedServiceText;
        if (rDetails) rDetails.textContent = `${period} (${type})`;
        if (rQueueNum) rQueueNum.textContent = queueNumber;

        if (joinButton) joinButton.textContent = "Joined Queue";
    } catch (error) {
        if (monitorWindow) monitorWindow.close();
        console.error("Failed to create queue ticket:", error);
        alert(error.message || "Unable to join the queue. Please try again.");
        if (joinButton) joinButton.disabled = false;
    }
}

// Helper function to render and launch the standalone ticket & monitoring tab
function escapeHtml(value) {
    return String(value ?? "").replace(/[&<>"']/g, character => ({
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#39;"
    })[character]);
}

function openMonitoringTab(data, monitoringWindow) {
    if (!monitoringWindow) {
        return;
    }

    const apiUrl = new URL("./api.php", window.location.href).href;
    monitoringWindow.document.write(`
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Queue Ticket #${escapeHtml(data.queueNumber)} - Live Monitor</title>
            <style>
                body {
                    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                    background-color: #f0f2f5;
                    margin: 0;
                    padding: 30px;
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    min-height: 90vh;
                }
                .ticket-card {
                    background: #ffffff;
                    border-radius: 12px;
                    box-shadow: 0 8px 24px rgba(0,0,0,0.12);
                    max-width: 450px;
                    width: 100%;
                    padding: 30px;
                    text-align: center;
                }
                .status-badge {
                    background-color: #e3f2fd;
                    color: #0d47a1;
                    padding: 6px 16px;
                    border-radius: 20px;
                    font-size: 14px;
                    font-weight: 600;
                    display: inline-block;
                    margin-bottom: 15px;
                }
                .queue-number {
                    font-size: 54px;
                    font-weight: 800;
                    color: #1a237e;
                    margin: 10px 0;
                    letter-spacing: 2px;
                }
                .info-table {
                    width: 100%;
                    margin-top: 25px;
                    border-collapse: collapse;
                    text-align: left;
                }
                .info-table td {
                    padding: 10px 4px;
                    border-bottom: 1px solid #edf2f7;
                    font-size: 15px;
                }
                .info-table td.label {
                    color: #718096;
                    font-weight: 500;
                    width: 40%;
                }
                .info-table td.value {
                    color: #2d3748;
                    font-weight: 600;
                }
                .footer-text {
                    margin-top: 25px;
                    font-size: 13px;
                    color: #a0aec0;
                }
            </style>
        </head>
        <body>
            <div class="ticket-card">
                <span id="ticketStatus" class="status-badge">● Waiting for Counter</span>
                <div style="color: #718096; font-size: 14px; margin-top: 5px;">Your Queue Number</div>
                <div class="queue-number">${escapeHtml(data.queueNumber)}</div>
                
                <table class="info-table">
                    <tr>
                        <td class="label">Name</td>
                        <td class="value">${escapeHtml(data.name)}</td>
                    </tr>
                    <tr>
                        <td class="label">Course & Year</td>
                        <td class="value">${escapeHtml(data.courseYear)}</td>
                    </tr>
                    <tr>
                        <td class="label">Service</td>
                        <td class="value">${escapeHtml(data.service)}</td>
                    </tr>
                    <tr>
                        <td class="label">Details</td>
                        <td class="value">${escapeHtml(data.details)}</td>
                    </tr>
                    <tr>
                        <td class="label">Lane Type</td>
                        <td class="value">${escapeHtml(data.lane)} ${data.relation ? '(' + escapeHtml(data.relation) + ')' : ''}</td>
                    </tr>
                    <tr>
                        <td class="label">Time Joined</td>
                        <td class="value">${escapeHtml(data.timestamp)}</td>
                    </tr>
                </table>

                <p class="footer-text">Please keep this tab open or screenshot your number while waiting.</p>
            </div>
            <script>
                const apiUrl = ${JSON.stringify(apiUrl)};
                const ticketId = ${data.ticketId};
                const statusElement = document.getElementById('ticketStatus');
                let pollTimer;
                async function refreshTicketStatus() {
                    try {
                        const response = await fetch(apiUrl + '?action=get_state', { cache: 'no-store' });
                        const result = await response.json();
                        if (!response.ok || !result.success || !Array.isArray(result.data)) {
                            throw new Error(result.message || 'Queue status unavailable');
                        }
                        const ticket = result.data.find(item => Number(item.ticket_id) === ticketId);
                        if (!ticket) {
                            statusElement.textContent = "Ticket not found in today's queue";
                            return;
                        }
                        const labels = {
                            waiting: '● Waiting for Counter',
                            serving: '● NOW SERVING - Please proceed',
                            completed: '✓ Transaction completed',
                            cancelled: 'Ticket skipped'
                        };
                        statusElement.textContent = labels[ticket.status] || 'Queue status unavailable';
                        if (ticket.status === 'completed' || ticket.status === 'cancelled') {
                            clearInterval(pollTimer);
                        }
                    } catch (error) {
                        console.error('Ticket status polling failed:', error);
                        statusElement.textContent = 'Connection lost. Reconnecting...';
                    }
                }
                refreshTicketStatus();
                pollTimer = setInterval(refreshTicketStatus, 1500);
            </script>
        </body>
        </html>
    `);
    monitoringWindow.document.close();
}

// Close Modal Function
function closeModal() {
    const receiptModal = document.getElementById("receiptModal");
    if (receiptModal) {
        receiptModal.style.display = "none";
    }
}