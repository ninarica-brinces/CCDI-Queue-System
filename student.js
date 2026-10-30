// Base counters for Regular (A) and Priority (P) lanes
// Loads existing counters from LocalStorage so numbers don't reset on page refresh
let counters = JSON.parse(localStorage.getItem("queueCounters")) || {
    regular: 0,  // Next regular starts at A-001
    priority: 0  // Next priority starts at P-001
};

// Maximum queue limit per lane
const QUEUE_LIMIT = 100;

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
function joinQueue() {
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

    // -------------------------------------------------------------
    // 2. CHECK QUEUE LIMIT (CAP AT 100)
    // -------------------------------------------------------------
    if (laneValue === "Regular" && counters.regular >= QUEUE_LIMIT) {
        alert(`Regular Lane queue has reached the maximum capacity of ${QUEUE_LIMIT}. Please try again later.`);
        return;
    }
    if (laneValue !== "Regular" && counters.priority >= QUEUE_LIMIT) {
        alert(`Priority Lane queue has reached the maximum capacity of ${QUEUE_LIMIT}. Please try again later.`);
        return;
    }

    // -------------------------------------------------------------
    // 3. QUEUE SEQUENCE LOGIC
    // -------------------------------------------------------------
    let prefix = "A";
    let currentNum = 0;

    if (laneValue === "Regular") {
        prefix = "A";
        counters.regular += 1;
        currentNum = counters.regular;
    } else {
        prefix = "P";
        counters.priority += 1;
        currentNum = counters.priority;
    }

    // Save updated counters back to LocalStorage
    localStorage.setItem("queueCounters", JSON.stringify(counters));

    const formattedNum = String(currentNum).padStart(3, "0");
    const queueNumber = `${prefix}-${formattedNum}`;

    // Safely Fetch Radio Selections
    const periodElem = document.querySelector('input[name="period"]:checked');
    const typeElem = document.querySelector('input[name="type"]:checked');

    const period = periodElem ? periodElem.value : "Prelim";
    const type = typeElem ? typeElem.value : "Partial Payment";

    // -------------------------------------------------------------
    // 4. STORE QUEUE TICKET DATA & OPEN MONITORING IN A NEW TAB
    // -------------------------------------------------------------
    const ticketData = {
        queueNumber: queueNumber,
        name: name,
        courseYear: `${course} (${year})`,
        service: selectedServiceText,
        details: `${period} (${type})`,
        lane: laneValue,
        relation: laneValue === "Parent" ? relation : "",
        timestamp: new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
    };

    localStorage.setItem("latestQueueTicket", JSON.stringify(ticketData));
    openMonitoringTab(ticketData);

    // -------------------------------------------------------------
    // 5. UPDATE LOCAL PAGE UI & UPDATE BUTTON TO "JOINED QUEUE"
    // -------------------------------------------------------------
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

    const button = document.getElementById("joinQueueBtn");
    if (button) {
        button.textContent = "Joined Queue"; 
        button.disabled = true;
    }
}

// Helper function to render and launch the standalone ticket & monitoring tab
function openMonitoringTab(data) {
    const monitoringWindow = window.open("", "_blank");
    if (!monitoringWindow) {
        alert("Please allow popups for this site to open your Queue Ticket & Monitoring window.");
        return;
    }

    monitoringWindow.document.write(`
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Queue Ticket #${data.queueNumber} - Live Monitor</title>
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
                <span class="status-badge">● Waiting for Counter</span>
                <div style="color: #718096; font-size: 14px; margin-top: 5px;">Your Queue Number</div>
                <div class="queue-number">${data.queueNumber}</div>
                
                <table class="info-table">
                    <tr>
                        <td class="label">Name</td>
                        <td class="value">${data.name}</td>
                    </tr>
                    <tr>
                        <td class="label">Course & Year</td>
                        <td class="value">${data.courseYear}</td>
                    </tr>
                    <tr>
                        <td class="label">Service</td>
                        <td class="value">${data.service}</td>
                    </tr>
                    <tr>
                        <td class="label">Details</td>
                        <td class="value">${data.details}</td>
                    </tr>
                    <tr>
                        <td class="label">Lane Type</td>
                        <td class="value">${data.lane} ${data.relation ? '(' + data.relation + ')' : ''}</td>
                    </tr>
                    <tr>
                        <td class="label">Time Joined</td>
                        <td class="value">${data.timestamp}</td>
                    </tr>
                </table>

                <p class="footer-text">Please keep this tab open or screenshot your number while waiting.</p>
            </div>
        </body>
        </html>
    `);
}

// Close Modal Function
function closeModal() {
    const receiptModal = document.getElementById("receiptModal");
    if (receiptModal) {
        receiptModal.style.display = "none";
    }
}