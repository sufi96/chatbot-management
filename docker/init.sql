-- Chatbot Management Hub - PostgreSQL Initialization Schema

CREATE TABLE IF NOT EXISTS systems (
    id VARCHAR(36) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    allowed_origins TEXT DEFAULT '*',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS bot_profiles (
    id VARCHAR(36) PRIMARY KEY,
    system_id VARCHAR(36) NOT NULL REFERENCES systems(id) ON DELETE CASCADE,
    name VARCHAR(255) NOT NULL,
    system_prompt TEXT DEFAULT 'You are a helpful, courteous, and accurate AI assistant.',
    provider_type VARCHAR(50) DEFAULT 'ollama',
    base_url VARCHAR(500) DEFAULT 'http://localhost:11434/v1',
    api_key VARCHAR(500) DEFAULT '',
    model_name VARCHAR(255) DEFAULT 'llama3.2',
    temperature REAL DEFAULT 0.7,
    max_tokens INTEGER DEFAULT 1024,
    widget_title VARCHAR(255) DEFAULT 'AI Assistant',
    widget_greeting TEXT DEFAULT 'Hello! How can I help you today?',
    widget_primary_color VARCHAR(20) DEFAULT '#4F46E5',
    widget_position VARCHAR(20) DEFAULT 'bottom-right',
    is_active INTEGER DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS chat_conversations (
    id VARCHAR(36) PRIMARY KEY,
    bot_id VARCHAR(36) NOT NULL REFERENCES bot_profiles(id) ON DELETE CASCADE,
    session_id VARCHAR(100) NOT NULL,
    origin VARCHAR(500) DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS chat_messages (
    id VARCHAR(36) PRIMARY KEY,
    conversation_id VARCHAR(36) NOT NULL REFERENCES chat_conversations(id) ON DELETE CASCADE,
    sender VARCHAR(20) NOT NULL,
    content TEXT NOT NULL,
    tokens_used INTEGER DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Seed initial default system and sample bot profile
INSERT INTO systems (id, name, description, allowed_origins)
VALUES 
('sys_default_01', 'Default Workspace', 'Primary system workspace for enterprise applications', '*')
ON CONFLICT (id) DO NOTHING;

INSERT INTO bot_profiles (
    id, system_id, name, system_prompt, provider_type, base_url, api_key, model_name, 
    temperature, max_tokens, widget_title, widget_greeting, widget_primary_color, widget_position, is_active
)
VALUES (
    'bot_demo_default', 
    'sys_default_01', 
    'Customer Support Bot', 
    'You are a professional customer support AI assistant. Answer user questions clearly, politely, and concisely.', 
    'ollama', 
    'http://localhost:11434/v1', 
    '', 
    'llama3.2', 
    0.7, 
    1024, 
    'Support Assistant', 
    'Hi there! 👋 How can we help you today?', 
    '#4F46E5', 
    'bottom-right', 
    1
)
ON CONFLICT (id) DO NOTHING;
