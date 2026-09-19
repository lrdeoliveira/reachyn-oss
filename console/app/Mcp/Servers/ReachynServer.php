<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\CheckMediaTool;
use App\Mcp\Tools\CloneVoiceTool;
use App\Mcp\Tools\DubVideoTool;
use App\Mcp\Tools\GenerateImageTool;
use App\Mcp\Tools\GenerateMusicTool;
use App\Mcp\Tools\GenerateNarrationTool;
use App\Mcp\Tools\GenerateVideoTool;
use App\Mcp\Tools\ListVoicesTool;
use App\Mcp\Tools\UploadMediaTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Reachyn')]
#[Version('1.1.0')]
#[Instructions('Gera imagem, vídeo, música e narração no Reachyn Studio. reachyn_generate_image e '
    .'reachyn_generate_narration são SÍNCRONAS (retornam a URL na hora). reachyn_generate_video, '
    .'reachyn_generate_music e reachyn_dub_video são ASSÍNCRONAS (retornam draftId; use '
    .'reachyn_check_media com esse draftId depois de alguns instantes/minutos pra pegar a URL final). '
    .'Pra usar uma foto/áudio local como referência (i2i/i2v/clonagem de voz), primeiro suba com '
    .'reachyn_upload_media e use a URL retornada em imageUrls/imageUrl. reachyn_list_voices lista as '
    .'vozes disponíveis pra narração/dublagem. reachyn_dub_video e reachyn_clone_voice são exclusivas '
    .'do plano Studio.')]
class ReachynServer extends Server
{
    protected array $tools = [
        GenerateImageTool::class,
        GenerateVideoTool::class,
        UploadMediaTool::class,
        CheckMediaTool::class,
        GenerateMusicTool::class,
        GenerateNarrationTool::class,
        DubVideoTool::class,
        CloneVoiceTool::class,
        ListVoicesTool::class,
    ];

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];
}
