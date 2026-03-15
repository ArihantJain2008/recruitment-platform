function getStoredUser() {
  try {
    return JSON.parse(localStorage.getItem("user") || "null");
  } catch (error) {
    return null;
  }
}

const user = getStoredUser();
if (!user || user.role !== "recruiter") {
  window.location.href = "/recruitment-platform/frontend/login.html";
}

let allJobs = [];
let selectedJobId = null;
let interviewApplicationId = null;

function resolveRecruiterId() {
  const ids = [user?.id, user?.user_id, user?.recruiter_id];

  for (const idValue of ids) {
    const id = Number.parseInt(idValue, 10);
    if (Number.isInteger(id) && id > 0) {
      return id;
    }
  }

  return null;
}

function parseExperience(rawValue) {
  if (!rawValue) {
    return null;
  }

  const value = Number.parseInt(rawValue, 10);
  if (!Number.isInteger(value) || value < 0) {
    return null;
  }

  return value;
}

async function fetchJson(url, options = {}) {
  const response = await fetch(url, options);
  const text = await response.text();

  let data = null;
  if (text) {
    try {
      data = JSON.parse(text);
    } catch (error) {
      console.error("Invalid JSON response from", url, text);
      throw new Error(`Server returned an invalid response (${response.status}).`);
    }
  }

  if (!response.ok) {
    throw new Error(data?.error || `Request failed (${response.status}).`);
  }

  return data;
}

async function loadJobs() {
  try {
    let jobs = await fetchJson("http://localhost/recruitment-platform/backend/jobs/list.php");

    if (!Array.isArray(jobs)) {
      jobs = [];
    }

    allJobs = jobs;
    displayJobs(jobs);
    updateJobSelect(jobs);
    await updateStats(jobs);
  } catch (error) {
    console.error("Error loading jobs:", error);
    allJobs = [];
    displayJobs([]);
    updateJobSelect([]);
    await updateStats([]);
  }
}

function displayJobs(jobs) {
  const container = document.getElementById("jobs-list");

  if (!jobs || jobs.length === 0) {
    container.innerHTML = `
      <div class="empty-state">
        <h3>No jobs yet</h3>
        <p>Create your first job posting to get started.</p>
      </div>
    `;
    return;
  }

  container.innerHTML = jobs
    .map((job) => {
      const experience = job.experience_required ?? "";
      return `
        <div class="card job-card">
          <div style="flex: 1;">
            <h3>${escapeHtml(job.title || "Untitled role")}</h3>
            ${job.description ? `<p style="color: #64748b; margin: 8px 0;">${escapeHtml(job.description)}</p>` : ""}
            <div style="display: flex; gap: 16px; margin-top: 12px; flex-wrap: wrap;">
              ${job.skills_required ? `<div><strong>Skills:</strong> ${escapeHtml(job.skills_required)}</div>` : ""}
              ${experience !== "" && experience !== null ? `<div><strong>Experience:</strong> ${escapeHtml(String(experience))} years</div>` : ""}
            </div>
          </div>
          <div style="display: flex; gap: 8px; align-items: flex-start;">
            <button class="btn-primary" onclick="editJob(${Number(job.id)})" style="margin: 0;">Edit</button>
            <button class="btn-danger" onclick="deleteJob(${Number(job.id)})" style="margin: 0;">Delete</button>
          </div>
        </div>
      `;
    })
    .join("");
}

function updateJobSelect(jobs) {
  const select = document.getElementById("job-select");
  const currentVal = select.value;
  const options = jobs
    .map((job) => `<option value="${Number(job.id)}">${escapeHtml(job.title || "Untitled role")}</option>`)
    .join("");

  select.innerHTML = `<option value="">Select a job...</option>${options}`;
  if (currentVal) {
    select.value = currentVal;
  }
}

async function updateStats(jobs) {
  document.getElementById("stat-jobs").innerText = jobs.length;

  if (!jobs.length) {
    document.getElementById("stat-applicants").innerText = "0";
    document.getElementById("stat-shortlisted").innerText = "0";
    document.getElementById("stat-rejected").innerText = "0";
    document.getElementById("stat-interviewed").innerText = "0";
    return;
  }

  const applicantLists = await Promise.all(
    jobs.map(async (job) => {
      try {
        const result = await fetchJson(
          `http://localhost/recruitment-platform/backend/applications/list.php?job_id=${Number(job.id)}`
        );
        return Array.isArray(result) ? result : [];
      } catch (error) {
        console.error("Error loading applicants for stats:", error);
        return [];
      }
    })
  );

  const applicants = applicantLists.flat();
  document.getElementById("stat-applicants").innerText = String(applicants.length);

  let shortlisted = 0;
  let rejected = 0;
  let interviewed = 0;

  applicants.forEach((app) => {
    if (app.status === "shortlisted") {
      shortlisted += 1;
    } else if (app.status === "rejected") {
      rejected += 1;
    } else if (app.status === "interviewed") {
      interviewed += 1;
    }
  });

  document.getElementById("stat-shortlisted").innerText = String(shortlisted);
  document.getElementById("stat-rejected").innerText = String(rejected);
  document.getElementById("stat-interviewed").innerText = String(interviewed);
}

async function loadApplicants() {
  const select = document.getElementById("job-select");
  const jobId = select.value;
  selectedJobId = jobId;

  const div = document.getElementById("applicants");

  if (!jobId) {
    div.innerHTML = "<p class='empty-state'>Please select a job.</p>";
    return;
  }

  try {
    const data = await fetchJson(
      `http://localhost/recruitment-platform/backend/applications/list.php?job_id=${Number(jobId)}`
    );

    if (!Array.isArray(data) || data.length === 0) {
      div.innerHTML = "<p class='empty-state'>No applicants yet for this job.</p>";
      return;
    }

    const cards = data
      .map((app) => {
        const appId = Number.parseInt(app.application_id ?? app.id, 10);
        if (!Number.isInteger(appId) || appId <= 0) {
          return "";
        }

        const statusRaw = String(app.status || "applied").toLowerCase();
        const allowedStatuses = ["applied", "shortlisted", "rejected", "interviewed"];
        const statusClass = allowedStatuses.includes(statusRaw) ? statusRaw : "applied";
        const hasInterviewData = Boolean(app.interview_time);
        const interviewTimeLabel = hasInterviewData
          ? `${escapeHtml(formatInterviewDateTime(app.interview_time))}${app.interview_timezone ? ` (${escapeHtml(app.interview_timezone)})` : ""}`
          : "";
        const meetLink = sanitizeUrl(app.interview_meet_link);
        const calendarLink = sanitizeUrl(app.interview_calendar_link);

        return `
          <div class="card">
            <h3>${escapeHtml(app.name || "Unnamed candidate")}</h3>
            <p>${escapeHtml(app.email || "No email")}</p>
            <p>Score: ${app.score ?? "N/A"}</p>
            <p>Status: <span class="status ${statusClass}">${escapeHtml(statusRaw.toUpperCase())}</span></p>
            ${hasInterviewData ? `
              <p><strong>Interview:</strong> ${interviewTimeLabel}</p>
              ${meetLink ? `<p><a href="${escapeHtml(meetLink)}" target="_blank" rel="noopener noreferrer">Open Meet Link</a></p>` : ""}
              ${calendarLink ? `<p><a href="${escapeHtml(calendarLink)}" target="_blank" rel="noopener noreferrer">Open Calendar Invite</a></p>` : ""}
            ` : ""}

            <button class="btn-primary" onclick="updateStatus(${appId}, 'shortlisted')">
              Shortlist
            </button>

            <button class="btn-secondary" onclick="openInterviewModal(${appId}, '${escapeJsString(app.name || "Candidate")}')">
              Interview
            </button>

            <button class="btn-danger" onclick="updateStatus(${appId}, 'rejected')">
              Reject
            </button>
          </div>
        `;
      })
      .filter(Boolean)
      .join("");

    div.innerHTML = cards || "<p class='empty-state'>No applicants yet for this job.</p>";
  } catch (error) {
    console.error("Error loading applicants:", error);
    div.innerHTML = "<p class='empty-state'>Error loading applicants.</p>";
  }
}

function showJobForm(jobId = null) {
  const modal = document.getElementById("job-form-modal");
  const formTitle = document.getElementById("job-form-title");
  const form = document.getElementById("job-form");

  if (jobId) {
    formTitle.textContent = "Edit Job";
    const job = allJobs.find((item) => Number(item.id) === Number(jobId));
    if (job) {
      document.getElementById("job-id").value = job.id;
      document.getElementById("job-title").value = job.title || "";
      document.getElementById("job-description").value = job.description || "";
      document.getElementById("job-skills").value = job.skills_required || "";
      document.getElementById("job-experience").value = job.experience_required ?? "";
    }
  } else {
    formTitle.textContent = "Create New Job";
    form.reset();
    document.getElementById("job-id").value = "";
  }

  document.body.classList.add("modal-open");
  modal.style.display = "flex";
}

function closeJobForm() {
  document.getElementById("job-form-modal").style.display = "none";
  document.getElementById("job-form").reset();
  document.getElementById("job-id").value = "";
  document.body.classList.remove("modal-open");
}

function getDefaultInterviewDatetime() {
  const now = new Date();
  now.setMinutes(0, 0, 0);
  now.setHours(now.getHours() + 1);
  return toLocalDatetimeValue(now);
}

function toLocalDatetimeValue(date) {
  const pad = (value) => String(value).padStart(2, "0");
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function openInterviewModal(applicationId, candidateName = "Candidate") {
  interviewApplicationId = Number(applicationId);

  const modal = document.getElementById("interview-modal");
  const form = document.getElementById("interview-form");
  document.getElementById("interview-application-id").value = String(applicationId);
  document.getElementById("interview-candidate-name").value = candidateName || "Candidate";
  document.getElementById("interview-time").value = getDefaultInterviewDatetime();
  document.getElementById("interview-duration").value = "30";
  document.getElementById("interview-meet-link").value = "";

  form.dataset.submitting = "false";
  document.body.classList.add("modal-open");
  modal.style.display = "flex";
}

function closeInterviewModal() {
  interviewApplicationId = null;
  const form = document.getElementById("interview-form");
  form.reset();
  document.getElementById("interview-application-id").value = "";
  document.getElementById("interview-modal").style.display = "none";
  document.body.classList.remove("modal-open");
}

async function confirmInterviewSchedule() {
  const form = document.getElementById("interview-form");
  if (form.dataset.submitting === "true") {
    return;
  }

  const applicationId = Number.parseInt(
    document.getElementById("interview-application-id").value || String(interviewApplicationId || ""),
    10
  );
  const interviewTime = document.getElementById("interview-time").value;
  const duration = Number.parseInt(document.getElementById("interview-duration").value || "30", 10);
  const meetLink = document.getElementById("interview-meet-link").value.trim();
  const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || "UTC";

  if (!Number.isInteger(applicationId) || applicationId <= 0) {
    alert("Invalid application selected for interview.");
    return;
  }

  if (!interviewTime) {
    alert("Interview date and time are required.");
    return;
  }

  if (!Number.isInteger(duration) || duration < 15 || duration > 240) {
    alert("Duration must be between 15 and 240 minutes.");
    return;
  }

  form.dataset.submitting = "true";

  try {
    const payload = {
      application_id: applicationId,
      status: "interviewed",
      interview_time: interviewTime,
      duration_minutes: duration,
      timezone
    };

    if (meetLink) {
      payload.meet_link = meetLink;
    }

    const data = await fetchJson("http://localhost/recruitment-platform/backend/applications/update_status.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload)
    });

    if (!data || data.success !== true) {
      throw new Error(data?.error || "Unknown error.");
    }

    closeInterviewModal();
    await loadApplicants();
    await loadJobs();

    const interviewTimeLabel = data.interview_time ? formatInterviewDateTime(data.interview_time) : interviewTime;
    alert(`Interview scheduled for ${interviewTimeLabel}. Candidate notification has been sent.`);
  } catch (error) {
    alert(`Failed to schedule interview: ${error.message}`);
  } finally {
    form.dataset.submitting = "false";
  }
}

async function saveJob() {
  const jobId = document.getElementById("job-id").value;
  const title = document.getElementById("job-title").value.trim();
  const description = document.getElementById("job-description").value.trim();
  const skills = document.getElementById("job-skills").value.trim();
  const experienceRaw = document.getElementById("job-experience").value.trim();

  if (!title) {
    alert("Job title is required.");
    return;
  }

  const experience = parseExperience(experienceRaw);
  if (experienceRaw && experience === null) {
    alert("Experience must be a valid non-negative number.");
    return;
  }

  const recruiterId = resolveRecruiterId();
  if (!jobId && !recruiterId) {
    alert("Session is invalid. Please log in again.");
    window.location.href = "/recruitment-platform/frontend/login.html";
    return;
  }

  const url = jobId
    ? "http://localhost/recruitment-platform/backend/jobs/update.php"
    : "http://localhost/recruitment-platform/backend/jobs/create.php";

  const body = jobId
    ? {
        id: Number.parseInt(jobId, 10),
        title,
        description,
        skills_required: skills,
        experience_required: experience
      }
    : {
        recruiter_id: recruiterId,
        title,
        description,
        skills_required: skills,
        experience_required: experience
      };

  try {
    const data = await fetchJson(url, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body)
    });

    if (!data || data.success !== true) {
      throw new Error(data?.error || "Unknown error.");
    }

    closeJobForm();
    await loadJobs();
    if (selectedJobId) {
      await loadApplicants();
    }
  } catch (error) {
    alert(`Failed to save job: ${error.message}`);
  }
}

function editJob(jobId) {
  showJobForm(jobId);
}

async function deleteJob(jobId) {
  if (!confirm("Are you sure you want to delete this job? This action cannot be undone.")) {
    return;
  }

  try {
    const data = await fetchJson("http://localhost/recruitment-platform/backend/jobs/delete.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ id: Number(jobId) })
    });

    if (!data || data.success !== true) {
      throw new Error(data?.error || "Unknown error.");
    }

    await loadJobs();
    document.getElementById("job-select").value = "";
    document.getElementById("applicants").innerHTML =
      "<p class='empty-state'>Please select a job to view applicants.</p>";
    selectedJobId = null;
  } catch (error) {
    alert(`Failed to delete job: ${error.message}`);
  }
}

async function updateStatus(id, status) {
  try {
    const data = await fetchJson("http://localhost/recruitment-platform/backend/applications/update_status.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ application_id: Number(id), status })
    });

    if (!data || data.success !== true) {
      throw new Error(data?.error || "Unknown error.");
    }

    await loadApplicants();
    await loadJobs();
  } catch (error) {
    alert(`Failed to update status: ${error.message}`);
  }
}

function escapeHtml(text) {
  const div = document.createElement("div");
  div.textContent = text ?? "";
  return div.innerHTML;
}

function escapeJsString(value) {
  return String(value ?? "")
    .replace(/\\/g, "\\\\")
    .replace(/'/g, "\\'")
    .replace(/\r/g, "\\r")
    .replace(/\n/g, "\\n");
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
  if (!value) {
    return "";
  }

  const normalized = String(value).replace(" ", "T");
  const date = new Date(normalized);
  if (Number.isNaN(date.getTime())) {
    return String(value);
  }

  return date.toLocaleString();
}

window.addEventListener("click", (event) => {
  const jobModal = document.getElementById("job-form-modal");
  const interviewModal = document.getElementById("interview-modal");
  if (event.target === jobModal) {
    closeJobForm();
  }
  if (event.target === interviewModal) {
    closeInterviewModal();
  }
});

document.addEventListener("keydown", (event) => {
  if (event.key === "Escape") {
    closeJobForm();
    closeInterviewModal();
  }
});

document.addEventListener("DOMContentLoaded", () => {
  loadJobs();
});
