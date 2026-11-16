<?php
declare(strict_types=1);

namespace App\Controllers\Ai;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Exceptions\HttpException;
use App\Services\Ai\AssistantService;

final class AssistantController extends Controller
{
    public function __construct(private AssistantService $assistant, private Database $db)
    {
    }

    /** Suggested questions tailored to what the user may do. */
    public static function suggestions(): array
    {
        $s = [];
        if (can('stock.view')) {
            $s[] = 'Which medicines are running low?';
            $s[] = 'Which products expire within the next 30 days?';
            $s[] = 'Which products should we reorder this week?';
        }
        if (can('analytics.view')) {
            $s[] = 'Which department consumed the most supplies this month?';
            $s[] = 'Which products have not moved in three months?';
        }
        if (can('valuation.view')) {
            $s[] = 'What is the total value of our inventory by category?';
        }
        if (can('suppliers.view')) {
            $s[] = 'How are our suppliers performing on delivery?';
        }
        if (can('analytics.view')) {
            $s[] = 'Are there any unusual stock movements?';
        }
        return array_slice($s, 0, 6);
    }

    public function index(Request $request): Response
    {
        $conversations = $this->db->fetchAll(
            'SELECT c.id, c.title, c.updated_at, (SELECT COUNT(*) FROM ai_messages m WHERE m.conversation_id = c.id AND m.role = \'user\') AS questions
               FROM ai_conversations c WHERE c.user_id = :u AND c.is_archived = 0 ORDER BY c.updated_at DESC LIMIT 50',
            ['u' => $this->userId()]
        );
        $active = (int) $request->query('c', 0);
        if ($active && !$this->assistant->ownConversation($active, $this->userId())) {
            throw new HttpException(404, 'Conversation not found.');
        }
        return view('ai/index', [
            'title'         => 'AI assistant',
            'conversations' => $conversations,
            'active'        => $active ?: null,
            'transcript'    => $active ? $this->assistant->transcript($active) : [],
            'available'     => $this->assistant->available(),
            'suggestions'   => self::suggestions(),
            'scripts'       => ['js/components/ai-chat.js', 'js/pages/ai-page.js'],
        ]);
    }

    /** POST /api/ai/chat {conversation_id?, message} */
    public function chat(Request $request): Response
    {
        $cid = $request->input('conversation_id');
        $result = $this->assistant->chat(ctype_digit((string) $cid) ? (int) $cid : null, (string) $request->input('message', ''), $this->userId());
        return json($result);
    }

    /** GET /api/ai/conversations/{id} */
    public function transcript(Request $request, int $id): Response
    {
        $c = $this->assistant->ownConversation($id, $this->userId()) ?? throw new HttpException(404, 'Conversation not found.');
        return json(['id' => (int) $c['id'], 'title' => $c['title'], 'messages' => $this->assistant->transcript($id)]);
    }

    /** POST /ai/conversations/{id} (rename / archive) */
    public function update(Request $request, int $id): Response
    {
        $this->assistant->ownConversation($id, $this->userId()) ?? throw new HttpException(404, 'Conversation not found.');
        if ($request->input('action') === 'archive') {
            $this->db->update('ai_conversations', ['is_archived' => 1], 'id = :id', ['id' => $id]);
            flash('success', 'Conversation archived.');
            return redirect(route('ai.index'));
        }
        $data = Validator::validate($request->all(), ['title' => 'required|string|min:1|max:150']);
        $this->db->update('ai_conversations', ['title' => $data['title']], 'id = :id', ['id' => $id]);
        flash('success', 'Conversation renamed.');
        return redirect(route('ai.index', [], ['c' => $id]));
    }
}
