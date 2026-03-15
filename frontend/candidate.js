console.log("candidate.js loaded");

function getStoredUser() {
  try {
    return JSON.parse(localStorage.getItem("user") || "null");
  } catch (error) {
    return null;
  }
}

// ---------- AUTH ----------
const user = getStoredUser();
if (!user || user.role !== "candidate") {
  window.location.href = "/recruitment-platform/frontend/login.html";
}

// ---------- DOM ----------
const jobsContainer = document.getElementById("available-jobs");
const notificationsContainer = document.getElementById("notifications");
const applicationsContainer = document.getElementById("applications");

// ---------- LOAD AVAILABLE JOBS ----------
function loadAvailableJobs() {
  if (!jobsContainer) {
    console.error("available-jobs container not found in HTML");
    return;
  }

  jobsContainer.innerHTML = "<p class='empty-state'>Loading jobs...</p>";

  Promise.all([
    fetch("http://localhost/recruitment-platform/backend/jobs/list.php")
      .then(res => res.json())
      .catch(() => []),
    fetch(
      `http://localhost/recruitment-platform/backend/applications/candidate_list.php?candidate_id=${user.id}`
    )
      .then(res => res.json())
      .catch(() => [])
  ])
    .then(([jobs, applications]) => {
      // Ensure we have arrays
      if (!Array.isArray(jobs)) jobs = [];
      if (!Array.isArray(applications)) applications = [];

      if (!Array.isArray(jobs) || jobs.length === 0) {
        jobsContainer.innerHTML = `
          <div class="empty-state">
            <h3>No jobs available</h3>
            <p>Please check back later.</p>
          </div>`;
        return;
      }

      const appliedJobIds = applications.map(a => Number(a.job_id));

      jobsContainer.innerHTML = jobs.map(job => {
        const applied = appliedJobIds.includes(Number(job.id));

        return `
          <div class="card job-card">
            <div>
              <h3>${escapeHtml(job.title)}</h3>
              <p>${escapeHtml(job.description || "")}</p>
              <p><b>Skills:</b> ${escapeHtml(job.skills_required || "")}</p>
              <p><b>Experience:</b> ${job.experience_required || 0}+ years</p>
            </div>

            <div>
              ${
                applied
                  ? `<button class="btn-secondary" disabled>Already Applied</button>`
                  : `
                    <label for="apply-text-${job.id}" style="display:block; font-size:12px; margin-bottom:6px; color:#475569;">
                      Write skills/profile text (optional)
                    </label>
                    <textarea
                      id="apply-text-${job.id}"
                      rows="3"
                      placeholder="Example: React, JavaScript, 3 years frontend experience..."
                      style="width: 260px; max-width: 100%; padding: 8px; border-radius: 10px; border: 2px solid #e2e8f0; margin-bottom: 8px;"
                    ></textarea>
                    <button class="btn-primary" onclick="applyToJob(${job.id})">Apply</button>
                  `
              }
            </div>
          </div>
        `;
      }).join("");
    })
    .catch(err => {
      console.error("Error loading jobs:", err);
      jobsContainer.innerHTML = "<p class='empty-state'>Failed to load jobs</p>";
    });
}

// ---------- APPLY ----------
function applyToJob(jobId) {
  const applyTextArea = document.getElementById(`apply-text-${jobId}`);
  const profileText = (applyTextArea?.value || "").trim();

  fetch("http://localhost/recruitment-platform/backend/applications/apply.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      job_id: jobId,
      candidate_id: user.id
    })
  })
    .then(res => res.json())
    .then(async data => {
      if (data.error) {
        alert(data.error);
        return;
      }

      if (profileText.length >= 10 && data.application_id) {
        try {
          const scoreRes = await fetch(
            "http://localhost/recruitment-platform/backend/applications/score_resume.php",
            {
              method: "POST",
              headers: { "Content-Type": "application/json" },
              body: JSON.stringify({
                application_id: data.application_id,
                resume_text: profileText
              })
            }
          );

          const scoreData = await scoreRes.json();
          if (scoreData?.success) {
            alert(`Application submitted. Current score: ${scoreData.score}/100`);
          }
        } catch (error) {
          console.error("Text scoring failed after apply:", error);
        }
      }

      loadAvailableJobs();
      loadApplications();
    })
    .catch(() => {
      alert("Failed to apply for job. Please try again.");
    });
}

// ---------- LOAD APPLICATIONS ----------
function loadApplications() {
  if (!applicationsContainer) return;

  applicationsContainer.innerHTML =
    "<p class='empty-state'>Loading applications...</p>";

  fetch(
    `http://localhost/recruitment-platform/backend/applications/candidate_list.php?candidate_id=${user.id}`
  )
    .then(res => res.json())
    .then(data => {
      if (!Array.isArray(data) || !data.length) {
        applicationsContainer.innerHTML = `
          <div class="empty-state">
            <h3>No applications yet</h3>
            <p>Apply to a job to track progress.</p>
          </div>`;
        return;
      }

      applicationsContainer.innerHTML = data.map(app => `
        <div class="card candidate-card">
          <div class="candidate-left">
            <h3>${escapeHtml(app.title || "Job Application")}</h3>
            <span class="status ${app.status}">
              ${app.status.toUpperCase()}
            </span>
            ${renderInterviewNotification(app)}

            <div class="candidate-upload">
              <label for="skill-text-${app.application_id}">Write your skills/profile text</label>
              <textarea
                id="skill-text-${app.application_id}"
                rows="4"
                placeholder="Example: HTML, CSS, JavaScript, React. 2 years experience building dashboards."
                style="width: 100%; padding: 10px; border-radius: 10px; border: 2px solid #e2e8f0; margin-bottom: 8px;"
              ></textarea>
              <button
                type="button"
                class="btn-secondary"
                onclick="submitApplicationText(${app.application_id})"
                style="margin-top: 0;"
              >
                Submit Text
              </button>
            </div>

            <div class="candidate-upload">
              <label>Or upload resume PDF</label>
              <input type="file"
                accept="application/pdf"
                onchange="uploadResume(${app.application_id}, this)">
            </div>
          </div>

          <div class="candidate-right">
            <div class="candidate-score">${app.score || 0}/100</div>
            <small>ATS Score</small>
          </div>
        </div>
      `).join("");
    })
    .catch(() => {
      applicationsContainer.innerHTML = "<p class='empty-state'>Failed to load applications</p>";
    });
}

function loadNotifications() {
  if (!notificationsContainer) return;

  notificationsContainer.innerHTML =
    "<p class='empty-state'>Loading notifications...</p>";

  fetch(
    `http://localhost/recruitment-platform/backend/applications/candidate_list.php?candidate_id=${user.id}`
  )
    .then(res => res.json())
    .then(data => {
      if (!Array.isArray(data)) {
        notificationsContainer.innerHTML =
          "<p class='empty-state'>No notifications right now.</p>";
        return;
      }

      const interviewItems = data.filter(app =>
        app.status === "interviewed" && (app.interview_time || app.interview_note || app.interview_meet_link)
      );

      if (!interviewItems.length) {
        notificationsContainer.innerHTML =
          "<p class='empty-state'>No notifications right now.</p>";
        return;
      }

      notificationsContainer.innerHTML = interviewItems
        .map(app => {
          const meetLink = sanitizeUrl(app.interview_meet_link);
          const calendarLink = sanitizeUrl(app.interview_calendar_link);
          const timeText = formatInterviewDateTime(app.interview_time);
          const timezoneText = app.interview_timezone ? ` (${escapeHtml(app.interview_timezone)})` : "";
          const noteText = app.interview_note
            ? escapeHtml(app.interview_note)
            : `Interview scheduled for ${escapeHtml(timeText)}${timezoneText}.`;

          return `
            <div class="card">
              <h3>Interview Invite: ${escapeHtml(app.title || "Job Application")}</h3>
              <p>${noteText}</p>
              ${app.interview_time ? `<p><strong>Time:</strong> ${escapeHtml(timeText)}${timezoneText}</p>` : ""}
              <div style="display:flex; gap: 8px; flex-wrap: wrap;">
                ${meetLink ? `<a class="btn-secondary" style="text-decoration:none; display:inline-block;" href="${escapeHtml(meetLink)}" target="_blank" rel="noopener noreferrer">Open Meet Link</a>` : ""}
                ${calendarLink ? `<a class="btn-primary" style="text-decoration:none; display:inline-block;" href="${escapeHtml(calendarLink)}" target="_blank" rel="noopener noreferrer">Open Calendar Invite</a>` : ""}
              </div>
            </div>
          `;
        })
        .join("");
    })
    .catch(() => {
      notificationsContainer.innerHTML =
        "<p class='empty-state'>Failed to load notifications.</p>";
    });
}

function submitApplicationText(applicationId) {
  const textarea = document.getElementById(`skill-text-${applicationId}`);
  const text = (textarea?.value || "").trim();

  if (text.length < 10) {
    alert("Please write a little more detail about your skills before submitting.");
    return;
  }

  fetch("http://localhost/recruitment-platform/backend/applications/score_resume.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      application_id: applicationId,
      resume_text: text
    })
  })
    .then(res => res.json())
    .then(data => {
      if (data.error) {
        alert(data.error);
        return;
      }

      alert(`Score updated: ${data.score}/100`);
      loadNotifications();
      loadApplications();
    })
    .catch(() => {
      alert("Failed to score your text. Please try again.");
    });
}

// ---------- UPLOAD RESUME ----------
async function uploadResume(applicationId, input) {
  const file = input.files[0];
  if (!file) return;

  const formData = new FormData();
  formData.append("application_id", applicationId);
  formData.append("resume", file);

  await fetch(
    "http://localhost/recruitment-platform/backend/applications/upload_resume.php",
    { method: "POST", body: formData }
  );

  const text = await extractTextFromPDF(file);

  await fetch(
    "http://localhost/recruitment-platform/backend/applications/score_resume.php",
    {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        application_id: applicationId,
        resume_text: text
      })
    }
  );

  loadApplications();
  loadNotifications();
}

// ---------- PDF TEXT ----------
async function extractTextFromPDF(file) {
  const buffer = await file.arrayBuffer();
  const pdf = await pdfjsLib.getDocument({ data: buffer }).promise;

  let text = "";
  for (let i = 1; i <= pdf.numPages; i++) {
    const page = await pdf.getPage(i);
    const content = await page.getTextContent();
    text += content.items.map(item => item.str).join(" ") + " ";
  }
  return text;
}

// ---------- UTILS ----------
function escapeHtml(text) {
  const div = document.createElement("div");
  div.textContent = text || "";
  return div.innerHTML;
}

function sanitizeUrl(url) {
  if (!url) {
    return "";
  }

  try {
    const parsed = new URL(url, window.location.origin);
    if (parsed.protocol === "http:" || parsed.protocol === "https:") {
      return parsed.href;
    }
  } catch (error) {
    return "";
  }

  return "";
}

function formatInterviewDateTime(value) {
  if (!value) return "";

  const normalized = String(value).replace(" ", "T");
  const date = new Date(normalized);
  if (Number.isNaN(date.getTime())) {
    return String(value);
  }

  return date.toLocaleString();
}

function renderInterviewNotification(app) {
  if (app.status !== "interviewed" || (!app.interview_time && !app.interview_note && !app.interview_meet_link)) {
    return "";
  }

  const meetLink = sanitizeUrl(app.interview_meet_link);
  const calendarLink = sanitizeUrl(app.interview_calendar_link);
  const timeText = formatInterviewDateTime(app.interview_time);
  const timezoneText = app.interview_timezone ? ` (${escapeHtml(app.interview_timezone)})` : "";
  const noteText = app.interview_note
    ? escapeHtml(app.interview_note)
    : `Interview scheduled for ${escapeHtml(timeText)}${timezoneText}.`;

  return `
    <div style="margin: 12px 0; padding: 12px; border-radius: 10px; border: 1px solid #cbd5e1; background: #f8fafc;">
      <p style="margin-bottom: 6px;"><strong>Interview Notification</strong></p>
      <p style="margin-bottom: 6px;">${noteText}</p>
      ${app.interview_time ? `<p style="margin-bottom: 6px;"><strong>Time:</strong> ${escapeHtml(timeText)}${timezoneText}</p>` : ""}
      <div style="display:flex; gap:8px; flex-wrap: wrap;">
        ${meetLink ? `<a class="btn-secondary" style="text-decoration:none; display:inline-block; margin-top:0;" href="${escapeHtml(meetLink)}" target="_blank" rel="noopener noreferrer">Join Meet</a>` : ""}
        ${calendarLink ? `<a class="btn-primary" style="text-decoration:none; display:inline-block; margin-top:0;" href="${escapeHtml(calendarLink)}" target="_blank" rel="noopener noreferrer">Calendar Invite</a>` : ""}
      </div>
    </div>
  `;
}

// ---------- INIT ----------
document.addEventListener("DOMContentLoaded", () => {
  loadAvailableJobs();
  loadNotifications();
  loadApplications();
  setInterval(loadNotifications, 30000);
});
