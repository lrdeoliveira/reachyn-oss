import React from 'react';
import {Composition} from 'remotion';
import {Captions, type CaptionsProps, defaultCaptionsProps} from './Captions';

// Composição única "captions". Dimensões/fps/duração vêm dos inputProps de cada render
// (calculateMetadata) — o default abaixo só existe pro Studio/preview abrir algo válido.
export const RemotionRoot: React.FC = () => {
  return (
    <Composition
      id="captions"
      component={Captions}
      durationInFrames={90}
      fps={30}
      width={1080}
      height={1920}
      defaultProps={defaultCaptionsProps}
      calculateMetadata={({props}) => {
        const p = props as CaptionsProps;
        return {
          durationInFrames: Math.max(1, Math.ceil(p.duration * p.fps)),
          fps: p.fps,
          width: p.width,
          height: p.height,
        };
      }}
    />
  );
};
