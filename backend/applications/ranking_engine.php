<?php

/**
 * Reusable scoring function required by the ranking feature.
 * Returns normalized score (0-100) with keyword details.
 */
function calculateMatchScore($jobDescription, $resumeSummary) {
    $jobText = rankingNormalizeText((string)$jobDescription);
    $resumeText = rankingNormalizeText((string)$resumeSummary);
    $config = rankingConfig();

    if ($jobText === "" || $resumeText === "") {
        return [
            "score" => 0,
            "matched_keywords" => [],
            "keyword_overlap" => 0,
            "total_keywords" => 0,
            "weighted_match_percent" => 0,
            "feedback" => rankingFeedbackLabel(0)
        ];
    }

    $keywords = rankingExtractKeywords($jobText);
    if (empty($keywords)) {
        return [
            "score" => 0,
            "matched_keywords" => [],
            "keyword_overlap" => 0,
            "total_keywords" => 0,
            "weighted_match_percent" => 0,
            "feedback" => rankingFeedbackLabel(0)
        ];
    }

    $totalWeight = 0.0;
    $matchedWeight = 0.0;
    $matchedKeywords = [];

    foreach ($keywords as $keyword => $meta) {
        $weight = floatval($meta["weight"]);
        $totalWeight += $weight;

        if (rankingTextContains($resumeText, $keyword)) {
            $matchedWeight += $weight;
            $matchedKeywords[] = $keyword;
        }
    }

    $totalKeywords = count($keywords);
    $overlapCount = count($matchedKeywords);
    $weightedRatio = $totalWeight > 0 ? ($matchedWeight / $totalWeight) : 0;
    $overlapRatio = $totalKeywords > 0 ? ($overlapCount / $totalKeywords) : 0;

    $rawScore = (($weightedRatio * $config["weighted_ratio_share"]) + ($overlapRatio * $config["overlap_ratio_share"])) * 100;
    $normalizedScore = max(0, min(100, intval(round($rawScore))));

    return [
        "score" => $normalizedScore,
        "matched_keywords" => $matchedKeywords,
        "keyword_overlap" => $overlapCount,
        "total_keywords" => $totalKeywords,
        "weighted_match_percent" => round($weightedRatio * 100, 2),
        "feedback" => rankingFeedbackLabel($normalizedScore)
    ];
}

/**
 * Calculates, sorts, ranks and persists scores for a given job.
 */
function rankCandidatesForJob($conn, $jobId, $jobDescription = "") {
    $jobId = intval($jobId);
    if ($jobId <= 0) {
        return [];
    }

    if (!ensureCandidateRankingSchema($conn)) {
        return [];
    }

    $descriptionText = trim((string)$jobDescription);
    if ($descriptionText === "") {
        $descriptionText = rankingLoadJobDescription($conn, $jobId);
    }

    $descriptionText = trim($descriptionText);
    if ($descriptionText === "") {
        rankingDeleteStaleScores($conn, $jobId, []);
        return [];
    }

    $candidates = rankingFetchCandidatesForJob($conn, $jobId);
    if (empty($candidates)) {
        rankingDeleteStaleScores($conn, $jobId, []);
        return [];
    }

    $scored = [];

    foreach ($candidates as $candidate) {
        $resumeSummary = trim((string)($candidate["resume_summary"] ?? ""));
        if ($resumeSummary === "") {
            $resumeSummary = trim((string)($candidate["ai_feedback"] ?? ""));
        }

        $match = calculateMatchScore($descriptionText, $resumeSummary);
        $matchedKeywords = array_values($match["matched_keywords"]);
        $matchedKeywordsText = implode(", ", $matchedKeywords);

        $scored[] = [
            "application_id" => intval($candidate["application_id"] ?? 0),
            "candidate_id" => intval($candidate["candidate_id"] ?? 0),
            "name" => (string)($candidate["name"] ?? ""),
            "score" => intval($match["score"]),
            "matched_keywords" => $matchedKeywords,
            "matched_keywords_text" => $matchedKeywordsText,
            "keyword_overlap" => intval($match["keyword_overlap"]),
            "total_keywords" => intval($match["total_keywords"]),
            "weighted_match_percent" => floatval($match["weighted_match_percent"]),
            "match_feedback" => (string)$match["feedback"],
            "rank" => 0,
            "is_top_3" => 0
        ];
    }

    usort($scored, function ($left, $right) {
        $scoreCompare = intval($right["score"]) <=> intval($left["score"]);
        if ($scoreCompare !== 0) {
            return $scoreCompare;
        }

        $overlapCompare = intval($right["keyword_overlap"]) <=> intval($left["keyword_overlap"]);
        if ($overlapCompare !== 0) {
            return $overlapCompare;
        }

        return strcmp((string)$left["name"], (string)$right["name"]);
    });

    $rank = 1;
    foreach ($scored as &$row) {
        $row["rank"] = $rank;
        $row["is_top_3"] = $rank <= 3 ? 1 : 0;
        $rank++;
    }
    unset($row);

    rankingPersistScores($conn, $jobId, $scored);

    return $scored;
}

/**
 * Ensures all required schema changes exist for ranking.
 */
function ensureCandidateRankingSchema($conn) {
    if (!ensureApplicationsResumeSummaryColumn($conn)) {
        return false;
    }

    $createTableSql = "
        CREATE TABLE IF NOT EXISTS candidate_scores (
            id INT AUTO_INCREMENT PRIMARY KEY,
            candidate_id INT NOT NULL,
            job_id INT NOT NULL,
            score DECIMAL(5,2) NOT NULL DEFAULT 0,
            `rank` INT NOT NULL DEFAULT 0,
            matched_keywords TEXT NULL,
            feedback VARCHAR(30) NOT NULL DEFAULT 'Low match',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_candidate_scores_job (job_id),
            KEY idx_candidate_scores_rank (job_id, `rank`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";

    if (!$conn->query($createTableSql)) {
        return false;
    }

    return rankingEnsureCandidateScoreColumns($conn);
}

function ensureApplicationsResumeSummaryColumn($conn) {
    $columns = rankingTableColumns($conn, "applications");
    if (!is_array($columns)) {
        return false;
    }

    if (isset($columns["resume_summary"])) {
        return true;
    }

    return (bool)$conn->query("ALTER TABLE applications ADD COLUMN resume_summary TEXT NULL");
}

function rankingFeedbackLabel($score) {
    $score = intval($score);
    $config = rankingConfig();

    if ($score >= $config["strong_match_threshold"]) {
        return "Strong match";
    }

    if ($score >= $config["moderate_match_threshold"]) {
        return "Moderate match";
    }

    return "Low match";
}

function rankingConfig() {
    return [
        "skill_weight" => 3.0,
        "general_weight" => 1.0,
        "weighted_ratio_share" => 0.75,
        "overlap_ratio_share" => 0.25,
        "max_general_keywords" => 20,
        "strong_match_threshold" => 75,
        "moderate_match_threshold" => 45
    ];
}

function rankingExtractKeywords($jobText) {
    $config = rankingConfig();
    $skills = rankingSkillLexicon();
    $keywords = [];
    $skillTokenLookup = [];

    foreach ($skills as $skill) {
        if (!rankingTextContains($jobText, $skill)) {
            continue;
        }

        $keywords[$skill] = [
            "weight" => $config["skill_weight"],
            "type" => "skill"
        ];

        $parts = preg_split('/[^a-z0-9]+/i', $skill);
        foreach ($parts as $part) {
            $token = trim($part);
            if ($token !== "") {
                $skillTokenLookup[$token] = true;
            }
        }
    }

    $rawTokens = preg_split('/[^a-z0-9\+\#\.]+/i', $jobText);
    $stopWords = rankingStopWords();
    $frequency = [];

    foreach ($rawTokens as $token) {
        $cleanToken = strtolower(trim((string)$token));

        if ($cleanToken === "" || strlen($cleanToken) < 3) {
            continue;
        }

        if (isset($stopWords[$cleanToken])) {
            continue;
        }

        if (isset($skillTokenLookup[$cleanToken])) {
            continue;
        }

        if (preg_match('/^\d+$/', $cleanToken)) {
            continue;
        }

        if (!isset($frequency[$cleanToken])) {
            $frequency[$cleanToken] = 0;
        }
        $frequency[$cleanToken]++;
    }

    arsort($frequency);
    $generalAdded = 0;
    foreach ($frequency as $token => $count) {
        if ($generalAdded >= $config["max_general_keywords"]) {
            break;
        }

        if (isset($keywords[$token])) {
            continue;
        }

        $keywords[$token] = [
            "weight" => $config["general_weight"],
            "type" => "general"
        ];
        $generalAdded++;
    }

    return $keywords;
}

function rankingNormalizeText($text) {
    $value = strtolower((string)$text);
    $value = str_replace(["\r", "\n", "\t"], " ", $value);
    $value = preg_replace('/[^a-z0-9\+\#\.\s]/', ' ', $value);
    $value = preg_replace('/\s+/', ' ', $value);
    return trim((string)$value);
}

function rankingTextContains($haystack, $needle) {
    $haystack = rankingNormalizeText((string)$haystack);
    $needle = rankingNormalizeText((string)$needle);

    if ($haystack === "" || $needle === "") {
        return false;
    }

    $pattern = '/(^|[^a-z0-9])' . preg_quote($needle, '/') . '([^a-z0-9]|$)/i';
    if (@preg_match($pattern, $haystack) === 1) {
        return true;
    }

    return strpos($haystack, $needle) !== false;
}

function rankingSkillLexicon() {
    return [
        "php",
        "mysql",
        "sql",
        "javascript",
        "typescript",
        "html",
        "css",
        "react",
        "angular",
        "vue",
        "node",
        "node.js",
        "express",
        "laravel",
        "symfony",
        "python",
        "java",
        "c#",
        "c++",
        "go",
        "ruby",
        "django",
        "flask",
        "spring",
        "rest",
        "graphql",
        "api",
        "microservices",
        "docker",
        "kubernetes",
        "aws",
        "azure",
        "gcp",
        "git",
        "ci/cd",
        "devops",
        "mongodb",
        "postgresql",
        "redis",
        "testing",
        "unit testing",
        "automation",
        "problem solving",
        "communication",
        "leadership",
        "agile",
        "scrum",
        "data analysis",
        "machine learning"
    ];
}

function rankingStopWords() {
    $words = [
        "a", "an", "and", "are", "as", "at", "be", "by", "can", "for", "from", "in", "into",
        "is", "it", "of", "on", "or", "our", "that", "the", "their", "this", "to", "we",
        "will", "with", "you", "your", "using", "must", "should", "have", "has", "had",
        "role", "job", "candidate", "required", "requirements", "preferred", "plus", "experience"
    ];

    $lookup = [];
    foreach ($words as $word) {
        $lookup[$word] = true;
    }

    return $lookup;
}

function rankingLoadJobDescription($conn, $jobId) {
    $columns = rankingTableColumns($conn, "jobs");
    if (!is_array($columns)) {
        return "";
    }

    $descriptionExpr = isset($columns["description"]) ? "description" : "''";
    $skillsExpr = isset($columns["skills_required"])
        ? "skills_required"
        : (isset($columns["requirements"]) ? "requirements" : "''");

    $stmt = $conn->prepare("SELECT {$descriptionExpr} AS description_text, {$skillsExpr} AS skills_text FROM jobs WHERE id = ?");
    if (!$stmt) {
        return "";
    }

    $stmt->bind_param("i", $jobId);
    $stmt->execute();
    $result = $stmt->get_result();

    if (!$result || $result->num_rows === 0) {
        return "";
    }

    $row = $result->fetch_assoc();
    $description = trim((string)($row["description_text"] ?? ""));
    $skills = trim((string)($row["skills_text"] ?? ""));

    return trim($description . " " . $skills);
}

function rankingFetchCandidatesForJob($conn, $jobId) {
    $columns = rankingTableColumns($conn, "applications");
    if (!is_array($columns)) {
        return [];
    }

    $resumeSummaryExpr = isset($columns["resume_summary"]) ? "applications.resume_summary" : "NULL AS resume_summary";
    $aiFeedbackExpr = isset($columns["ai_feedback"]) ? "applications.ai_feedback" : "NULL AS ai_feedback";

    $sql = "
        SELECT
            applications.id AS application_id,
            applications.candidate_id,
            users.name,
            {$resumeSummaryExpr},
            {$aiFeedbackExpr}
        FROM applications
        LEFT JOIN users ON users.id = applications.candidate_id
        WHERE applications.job_id = ?
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param("i", $jobId);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    while ($result && $row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    return $rows;
}

function rankingPersistScores($conn, $jobId, $rankedRows) {
    $deleteStmt = $conn->prepare("DELETE FROM candidate_scores WHERE candidate_id = ? AND job_id = ?");
    $insertStmt = $conn->prepare(
        "INSERT INTO candidate_scores (candidate_id, job_id, score, `rank`, matched_keywords, feedback)
         VALUES (?, ?, ?, ?, ?, ?)"
    );

    if (!$deleteStmt || !$insertStmt) {
        return;
    }

    $candidateIds = [];

    foreach ($rankedRows as $row) {
        $candidateId = intval($row["candidate_id"] ?? 0);
        if ($candidateId <= 0) {
            continue;
        }

        $score = floatval($row["score"] ?? 0);
        $rank = intval($row["rank"] ?? 0);
        $matchedKeywordsText = (string)($row["matched_keywords_text"] ?? "");
        $feedback = (string)($row["match_feedback"] ?? rankingFeedbackLabel($score));

        $deleteStmt->bind_param("ii", $candidateId, $jobId);
        $deleteStmt->execute();

        $insertStmt->bind_param("iidiss", $candidateId, $jobId, $score, $rank, $matchedKeywordsText, $feedback);
        $insertStmt->execute();

        $candidateIds[] = $candidateId;
    }

    rankingDeleteStaleScores($conn, $jobId, $candidateIds);
}

function rankingDeleteStaleScores($conn, $jobId, $candidateIds) {
    $jobId = intval($jobId);
    if ($jobId <= 0) {
        return;
    }

    $candidateIds = array_values(array_unique(array_map("intval", (array)$candidateIds)));

    if (empty($candidateIds)) {
        $conn->query("DELETE FROM candidate_scores WHERE job_id = " . $jobId);
        return;
    }

    $inClause = implode(",", $candidateIds);
    $sql = "DELETE FROM candidate_scores WHERE job_id = {$jobId} AND candidate_id NOT IN ({$inClause})";
    $conn->query($sql);
}

function rankingEnsureCandidateScoreColumns($conn) {
    $columns = rankingTableColumns($conn, "candidate_scores");
    if (!is_array($columns)) {
        return false;
    }

    $changes = [];

    if (!isset($columns["job_id"])) {
        $changes[] = "ADD COLUMN job_id INT NOT NULL DEFAULT 0 AFTER candidate_id";
    }
    if (!isset($columns["score"])) {
        $changes[] = "ADD COLUMN score DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER job_id";
    }
    if (!isset($columns["rank"])) {
        $changes[] = "ADD COLUMN `rank` INT NOT NULL DEFAULT 0 AFTER score";
    }
    if (!isset($columns["matched_keywords"])) {
        $changes[] = "ADD COLUMN matched_keywords TEXT NULL AFTER `rank`";
    }
    if (!isset($columns["feedback"])) {
        $changes[] = "ADD COLUMN feedback VARCHAR(30) NOT NULL DEFAULT 'Low match' AFTER matched_keywords";
    }
    if (!isset($columns["created_at"])) {
        $changes[] = "ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP";
    }
    if (!isset($columns["updated_at"])) {
        $changes[] = "ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP";
    }

    if (!empty($changes)) {
        $alterSql = "ALTER TABLE candidate_scores " . implode(", ", $changes);
        if (!$conn->query($alterSql)) {
            return false;
        }
    }

    $jobIndex = $conn->query("SHOW INDEX FROM candidate_scores WHERE Key_name = 'idx_candidate_scores_job'");
    if (!$jobIndex || $jobIndex->num_rows === 0) {
        $conn->query("ALTER TABLE candidate_scores ADD KEY idx_candidate_scores_job (job_id)");
    }

    $rankIndex = $conn->query("SHOW INDEX FROM candidate_scores WHERE Key_name = 'idx_candidate_scores_rank'");
    if (!$rankIndex || $rankIndex->num_rows === 0) {
        $conn->query("ALTER TABLE candidate_scores ADD KEY idx_candidate_scores_rank (job_id, `rank`)");
    }

    return true;
}

function rankingTableColumns($conn, $tableName) {
    $safeTableName = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$tableName);
    if ($safeTableName === "") {
        return null;
    }

    $result = $conn->query("SHOW COLUMNS FROM {$safeTableName}");
    if (!$result) {
        return null;
    }

    $columns = [];
    while ($row = $result->fetch_assoc()) {
        $columns[$row["Field"]] = true;
    }

    return $columns;
}
