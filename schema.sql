CREATE TABLE IF NOT EXISTS tickets (
      id          INT PRIMARY KEY,
      subject     VARCHAR(255) NOT NULL,
      body        TEXT NOT NULL,
      category    VARCHAR(50) NOT NULL,
      root_cause  VARCHAR(100) NOT NULL,
      resolution  TEXT NOT NULL,
      created_at  DATE NOT NULL,
      INDEX idx_category (category)
  );

  CREATE TABLE IF NOT EXISTS ticket_embeddings (
      ticket_id   INT NOT NULL,
      model       VARCHAR(100) NOT NULL,
      vector      JSON NOT NULL,
      created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (ticket_id, model),
      FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
  );

  CREATE TABLE IF NOT EXISTS triage_log (
      id          INT AUTO_INCREMENT PRIMARY KEY,
      subject     VARCHAR(255) NOT NULL,
      body        TEXT NOT NULL,
      similar_ids JSON NOT NULL,
      draft       TEXT,
      action      ENUM('auto_reply','human_review','escalate') NOT NULL,
      confidence  DECIMAL(3,2),
      reason      TEXT,
      rule_fired  VARCHAR(100),
      model       VARCHAR(100),
      created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  );